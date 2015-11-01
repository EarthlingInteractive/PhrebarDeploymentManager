<?php

class EarthIT_PhrebarDeploymentManager_Zoox {
	protected $user;
	protected $dir;
	public function __construct( $user, $dir=null ) {
		$this->user = $user;
		$this->dir = $dir;
	}
	public function sys( $cmd, $dir=null ) {
		$cmd = "sudo -u ".escapeshellarg($this->user)." sh -c ".escapeshellarg($cmd);

		if( $dir === null ) $dir = $this->dir;
		if( $dir !== null ) $cmd = "cd ".escapeshellarg($dir)." && ".$cmd;
		
		fwrite(STDERR, "$ $cmd\n");
		system( $cmd, $status );
		if( $status ) throw new Exception("Shell command failed with status $status: $cmd");
	}
	public function mkdirs( $dir ) {
		$this->sys("mkdir -p ".escapeshellarg($dir));
	}
	public function chdir($dir) {
		return new self( $this->user, $dir );
	}
}

class EarthIT_PhrebarDeploymentManager
{
	protected $dmDir;
	public function __construct($dmDir) {
		$this->dmDir = $dmDir;
	}
	
	public function loadConfig($file=null) {
		if( $file === null ) $file = "{$this->dmDir}/config.json";
		$config = json_decode(file_get_contents($file),true);
		if( $config === null ) throw new Exception("Failed to load config from $file");
		return $config;
	}
	
	protected $config = null;
	protected function getConfig($path=[]) {
		if( $this->config === null ) $this->config = $this->loadConfig();
		$config = $this->config;
		if( is_string($path) ) $path = explode('/', $path);
		foreach( $path as $pp ) {
			if( !isset($config[$pp]) ) {
				throw new Exception("Config entry '$pp' not present");
			}
			$config = $config[$pp];
		}
		return $config;
	}
	
	protected $zooxen = [];
	protected function zoox($username) {
		if( !isset($this->zooxen[$username]) ) {
			$user = $this->getConfig(['users',$username]);
			$this->zooxen[$username] = new EarthIT_PhrebarDeploymentManager_Zoox($user['username']);
		}
		return $this->zooxen[$username];
	}
	
	protected static function template($infile, $outfile, array $vars, $chown) {
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
	
	public function defaultDeploymentInfo(array $deployment) {
		$config = $this->loadConfig();
		
		$deploymentName = $deployment['name'];
		$domainPostfix = $config['hostname-postfix'];
		
		$dir = $this->getConfig('deployment-root').'/'.$deploymentName;
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
		$DOZ = $this->zoox('deployment-owner');
		$AMZ = $this->zoox('apache-manager');
		$PGZ = $this->zoox('postgres');
		$success = false;
		try {
			$this->destroyDeployment($deployment, false);

			$dir = $deployment['directory'];
			
			$DOZ->mkdirs($dir);
			$DOZ2 = $DOZ->chdir($dir);
			$DOZ2->sys("git init");
			$DOZ2->sys("git remote add origin ".escapeshellarg($deployment['source-repo']));
			$DOZ2->sys("git fetch --all");
			$DOZ2->sys("git reset --hard ".escapeshellarg($deployment['source-commit']));
			
			$this->template("{$this->dmDir}/create-database.sql.template", "{$dir}/.create-database.sql", $deployment);
			$PGZ->sys("psql <{$dir}/.create-database.sql");
			
			$this->template("{$this->dmDir}/dbc.json.template", "{$dir}/config/dbc.json", $deployment);
			$this->template("{$this->dmDir}/email-transport.json.template", "{$dir}/config/email-transport.json", $deployment);
			
			$DOZ->sys("make -C ".escapeshellarg($dir)." redeploy");
			
			$this->template("{$this->dmDir}/vhost.template", "{$dir}/.vhost", $deployment);
			
			// Remove any existing vhost files
			
			$vhostFile = $deployment['vhost-file'];
			$vhostLink = $deployment['vhost-link'];
			
			$AMZ->sys("mv ".escapeshellarg("{$dir}/.vhost")." ".escapeshellarg($vhostFile));
			$AMZ->sys("ln -s ".escapeshellarg($vhostFile)." ".escapeshellarg($vhostLink));
			$AMZ->sys("apache2ctl restart");
			
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
		$DOZ = $this->zoox('deployment-owner');
		$AMZ = $this->zoox('apache-manager');
		$PGZ = $this->zoox('postgres');
		$DOZ->sys("rm -rf ".escapeshellarg($dir));
		foreach( ['vhost-file','vhost-link'] as $k ) {
			$AMZ->sys("rm -f ".escapeshellarg($deployment[$k]));
		}
		$PGZ->sys('psql -c '.escapeshellarg('DROP DATABASE IF EXISTS "'.$deployment['dbname'].'"'));
	}
}
