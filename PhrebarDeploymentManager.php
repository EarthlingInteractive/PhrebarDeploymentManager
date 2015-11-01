<?php

class EarthIT_PhrebarDeploymentManager
{
	protected $dmDir;
	public function __construct($dmDir) {
		$this->dmDir = $dmDir;
	}
	
	const SYS_IGNORE_ERRORS = 1;
	const SYS_SUDO   = 2;
	const SYS_SUDO_N = 6;
	
	protected function sys($cmd, $flags=0) {
		if(  ($flags & self::SYS_SUDO_N) === self::SYS_SUDO_N ) $cmd = "sudo -n $cmd";
		else if( ($flags & self::SYS_SUDO) === self::SYS_SUDO ) $cmd = "sudo $cmd";
		fwrite(STDERR, "$ $cmd\n");
		system($cmd, $status);
		if( $status and !($flags & self::SYS_IGNORE_ERRORS) ) {
			throw new Exception("Shell command failed with status $status: $cmd");
		}
	}
	
	protected static function template($infile, $outfile, array $vars) {
		$source = file_get_contents($infile);
		if( $source === false ) {
			throw new Exception("Failed to read $infile");
		}
		foreach( $vars as $k=>$v ) {
			$source = str_replace("{{$k}}", $v, $source);
		}
		if( file_put_contents($outfile, $source) === false ) {
			throw new Exception("Failed to write $outfile");
		}
	}
	
	public function loadConfig($file=null) {
		if( $file === null ) $file = "{$this->dmDir}/config.json";
		$config = json_decode(file_get_contents($file),true);
		if( $config === null ) throw new Exception("Failed to load config from $file");
		return $config;
	}
	
	public function defaultDeploymentInfo(array $deployment) {
		$config = $this->loadConfig();
		
		$deploymentName = $deployment['name'];
		$domainPostfix = $config['hostname-postfix'];
		
		$dir = "{$this->dmDir}/deployments/{$deploymentName}";
		$docroot = "{$dir}/www";
		$hostname = "{$deploymentName}{$domainPostfix}";
		$vhostFile = "/etc/apache2/sites-available/{$deploymentName}.conf";
		$vhostLink = "/etc/apache2/sites-enabled/001-{$deploymentName}.conf";
		$dbname = $deploymentName;
		$dbuser = $deploymentName;
		$dbpassword = $deploymentName;
		
		return [
			'name' => $deploymentName,
			'directory' => $dir,
			'hostname' => $hostname,
			'docroot' => $docroot,
			'dbname' => $dbname,
			'dbuser' => $dbuser,
			'dbpassword' => $dbpassword,
			'vhost-file' => $vhostFile,
			'vhost-link' => $vhostLink,
			'admin-email-address' => 'ei-ci-admin@mailinator.com',
		];
	}
	
	public function fillDeploymentInfo(array $deployment) {
		return $deployment + $this->defaultDeploymentInfo($deployment);
	}
	
	public function createDeployment(array $deployment) {
		$deployment = $this->fillDeploymentInfo($deployment);
		$success = false;
		try {
			$this->destroyDeployment($deployment, false);

			$dir = $deployment['directory'];
			
			mkdir($dir, 0755, true);
			$oldDir = getcwd();
			chdir($dir);
			try {
				$this->sys("git init");
				$this->sys("git remote add origin ".escapeshellarg($deployment['source-repo']));
				$this->sys("git fetch --all");
				$this->sys("git reset --hard ".escapeshellarg($deployment['source-commit']));
			} finally {
				chdir($oldDir);
			}
			
			$this->template("{$this->dmDir}/create-database.sql.template", "{$dir}/.create-database.sql", $deployment);
			$this->sys("sudo -u postgres psql <{$dir}/.create-database.sql");
			
			$this->template("{$this->dmDir}/dbc.json.template", "{$dir}/config/dbc.json", $deployment);
			$this->template("{$this->dmDir}/email-transport.json.template", "{$dir}/config/email-transport.json", $deployment);
			
			$this->sys("make -C ".escapeshellarg($dir)." redeploy");
			
			$this->template("{$this->dmDir}/vhost.template", "{$dir}/.vhost", $deployment);
			
			// Remove any existing vhost files
			
			$vhostFile = $deployment['vhost-file'];
			$vhostLink = $deployment['vhost-link'];
			
			$this->sys("mv ".escapeshellarg("{$dir}/.vhost")." ".escapeshellarg($vhostFile), self::SYS_SUDO);
			$this->sys("ln -s ".escapeshellarg($vhostFile)." ".escapeshellarg($vhostLink),   self::SYS_SUDO);
			$this->sys("apache2ctl restart",                                                 self::SYS_SUDO);
			
			$success = true;
		} finally {
			if( !$success ) {
				fwrite(STDERR, "Stuff went wrong.  Cleaning up...\n");
				$this->destroyDeployment($deployment, true);
			}
		}
	}
	
	public function destroyDeployment(array $deployment, $ignoreErrors=false) {
		$deployment = $this->fillDeploymentInfo($deployment);
		$dir = $deployment['directory'];
		$nsf = $ignoreErrors ? self::SYS_IGNORE_ERRORS : 0;
		$sf  = $ignoreErrors ? self::SYS_SUDO_N : self::SYS_SUDO;
		$this->sys("rm -rf ".escapeshellarg($dir)     , $nsf);
		foreach( ['vhost-file','vhost-link'] as $k ) {
			$this->sys("rm -f ".escapeshellarg($deployment[$k]), $sf );
		}
	}
}
