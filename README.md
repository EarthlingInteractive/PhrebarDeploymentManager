# Phrebar Deployment Manager

Creates and tears down Phrebar (and maybe eventually other types of)
deployments at your whim.

There are two basic commands:

```create-deployment <name> <repository URL> <commit ID>``` checks out
the specified Git commit from the specified repository, creates a
Postgres database and an Apache virtual host, writes the appropriate
files in the project's config directory, and runs ```make redeploy```.

```destroy-deployment <name>``` removes all the stuff created by
```create-deployment```.

```deployment-server``` is a script that listens for commands on
standard input and runs them.  Maybe eventually there will be options
to listen on some network port or something, making it a bit more
self-contained.
