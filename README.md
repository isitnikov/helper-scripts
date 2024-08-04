# Installation

1. Have magento-cloud installed and configured with API TOKEN.
2. Have cloud-dump installed and configured
3. Have warden installed Warden. Use command: ```brew install wardenenv/warden/warden && warden svc up```
4. Have this repo cloned. Use command: ```git clone git@github.com:npuchko/helper-scripts.git```
5. Create symlinks to bin files:
```shell
sudo ln -s /path/to/this/repo/cloned/bin/wdi /usr/local/bin/wdi
sudo ln -s /path/to/this/repo/cloned/bin/warden-remove /usr/local/bin/warden-remove

```
6. Install tampermonkey scripts here https://github.com/npuchko/helper-scripts/tree/master/tampermonkey


# Usage
## To install
1. Create directory for all the client dumps (for example /Users/<user>/www/clients)
2. Make sure that docker is running and warden containers too (run ```warden svc up``` to run warden containers)
3. Go to that directory
4. Run command 
```shell
wdi ABCDE-12345 abcdefjhqkf staging
```
- where ABCDE-12345 is number of the ticket,
- abcdefjhqkf is the magento cloud project ID
- staging is the branch name (environment id)
5. Wait for the installation.
6. Type your sudo password to add local domain to /etc/hosts file

## To remove
1. Go to your "clients" dir (for example /Users/<user>/www/clients)
2. Run ```warden-remove abcde12345``` where abcde12345 is the name of folder with the dumps.
3. This command will completely remove all docker volume data, images, networks and directory with files.

# Known Issues

1. 503 Backend fetch failed varnish - disable Magento_Csp (it puts lots of headers)
2. Endless redirect to self when open admin url: go to pub/index.php and add 
```php 
$_SERVER['HTTPS'] = 'https';
``` 
to very start of the file


# MASTER SLAVE configuration
1. Create config for master inside magento root dir: .warden/db/master/master.cnf with following content
```shell
[mariadb]
log-bin                         # enable binary logging
server_id=3000                  # used to uniquely identify the server
log-basename=my-mariadb         # used to be independent of hostname changes
                                # (otherwise name is <datadir>/mysql-bin)
#binlog-format=MIXED            #default
```
2. Create config for slave inside magento root dir: .warden/db/slave/slave.cnf with following content
```shell
[mariadb]
server_id=3001                  # used to uniquely identify the server
log-basename=my-mariadb         # used to be independent of hostname changes
                                # (otherwise name is <datadir>/mysql-bin)
replicate_do_db=magento      # replicate only this DB
#binlog-format=MIXED            #default
```
3. UPDATE your docker-compose file .warden/warden-env.yml with following
```yaml
version: "3.5"
services:
  php-fpm:
    depends_on:
      - dbslave # <----- THIS LINE to add access to slave from php

  php-debug:
    depends_on:
      - dbslave # <----- THIS LINE to add access to slave from php+xdebug
        
  db:
    volumes:
      - ./.warden/db/master/master.cnf:/etc/mysql/conf.d/master.cnf # <----- adding our config for master

  dbslave: # <----- adding our config for slave
    hostname: "${WARDEN_ENV_NAME}-mariadbslave"
    image: mariadb:${MARIADB_VERSION:-10.4}
    environment:
      - MYSQL_ROOT_PASSWORD=magento
      - MYSQL_DATABASE=magento
      - MYSQL_USER=magento
      - MYSQL_PASSWORD=magento
    volumes:
      - dbdataslave:/var/lib/mysql
      - ./.warden/db/slave/slave.cnf:/etc/mysql/conf.d/slave.cnf
    depends_on:
      - db # <----- we are depend on primary db

volumes:
  dbdataslave:
```
4. Reinstantiate containers:
```shell
warden env down
warden env up
```

5. Import dumps to secondary db:
```shell
gunzip -c ../php81.database.sql.gz |  warden env exec -T dbslave mysql -uroot -pmagento --database=magento
```

6. Open mysql console to PRIMARY db as ROOT user and run following:
```mysql
CREATE USER 'repluser'@'%' IDENTIFIED BY 'replsecret';
GRANT REPLICATION SLAVE ON *.* TO 'repluser'@'%';
```

7. Retrieve IP address for master DB container:
```shell
warden env exec db cat /etc/hosts | grep mariadb | awk '{print $1}'
```
8. Open mysql console to SLAVE as ROOT user  db and run:
```mysql
CHANGE MASTER TO
    MASTER_HOST='<IP GOES HERE>',
    MASTER_USER='repluser',
    MASTER_PASSWORD='replsecret',
    MASTER_PORT=3306,
    MASTER_CONNECT_RETRY=10;

START SLAVE;
```

to check status use:
```mysql
show master status; -- on master
show slave status; -- on slave
```
Use this article to debug if something goes wrong:
https://mariadb.org/mariadb-replication-using-containers/

9. Configure app/etc/env.php to use slave:
```php
<?php

return [
    
    'db' => [
        'connection' => [
            'default' => [
                'host' => 'db',
                'username' => 'magento',
                'dbname' => 'magento',
                'password' => 'magento'
            ],
            'indexer' => [
                'host' => 'db',
                'username' => 'magento',
                'dbname' => 'magento',
                'password' => 'magento'
            ]
        ],

        'slave_connection' => [ // <------------ this part
            'default' => [
                'host' => 'dbslave',
                'username' => 'root',
                'dbname' => 'magento',
                'password' => 'magento',
                'model' => 'mysql4',
                'engine' => 'innodb',
                'initStatements' => 'SET NAMES utf8;',
                'active' => '1',
                'synchronous_replication' => true 
            ]
        ]
    ],
];
```
