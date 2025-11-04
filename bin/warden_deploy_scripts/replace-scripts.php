<?php
$magentoRootDir = dirname(__DIR__);

$envFile = $magentoRootDir . '/.env';

if (!is_readable($envFile)) {
    fwrite(STDERR, "⚠️  File .env not found in " . __DIR__ . "\n");
    exit(1);
}

// Прочитати файл і завантажити кожну змінну в $_ENV
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    // підтримка формату KEY=value або KEY="value"
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches)) {
        $key = $matches[1];
        $value = trim($matches[2], " \t\n\r\0\x0B\"'");
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

// Визначення пошукового рушія
if (($_ENV['WARDEN_OPENSEARCH'] ?? '0') === '1') {
    $_ENV['SEARCH_ENGINE'] = 'opensearch';
    $_ENV['SEARCH_VERSION'] = $_ENV['OPENSEARCH_VERSION'] ?? '';
} elseif (($_ENV['WARDEN_ELASTICSEARCH'] ?? '0') === '1') {
    $_ENV['SEARCH_ENGINE'] = 'elasticsearch';
    $_ENV['SEARCH_VERSION'] = $_ENV['ELASTICSEARCH_VERSION'] ?? '';
} else {
    $_ENV['SEARCH_ENGINE'] = 'none';
    $_ENV['SEARCH_VERSION'] = '';
}

//var_dump(getDomainsFromEnv(__DIR__));
//echo PHP_EOL;
//die;
$command = $argv[1] ?? null;

try {
    switch ($command) {
        case 'conf':
            replaceConfigPhp($magentoRootDir);
            break;
        case 'conf-revert':
            configRevert($magentoRootDir);
            break;
        case 'env':
            replaceEnvConfig($magentoRootDir);
            break;
        case 'env-revert':
            envRevert($magentoRootDir);
            break;
        case 'db':
            replaceDb($magentoRootDir);
            break;
        case 'db-revert':
            dbRevert($magentoRootDir);
            break;
        default:

            die("Command not specified. For replacing app/etc/env.php run php replace-scripts.php env");
            break;
    }
} catch (\Throwable $e) {
    echo "THERE IS AN ERROR: " . $e->getMessage() . PHP_EOL;
}


function getDomainsFromEnv($path) {
    $config = include $path . '/app/etc/env.php';

    $configPatcher = new ConfigPatcher($config);

    if (!$configPatcher->exists('system')) {
        return;
    }

    $domains = [];
    if (!empty($config['system']['default']['web']['unsecure']['base_url'])) {
        $domains[] = getDomain($config['system']['default']['web']['unsecure']['base_url']);
    }

    if (!empty($config['system']['default']['web']['secure']['base_url'])) {
        $domains[] = getDomain($config['system']['default']['web']['secure']['base_url']);
    }

    foreach ($config['system'] as $scope => $scopeConfig) {
        if ($scope === 'default') {
            continue;
        }

        foreach ($scopeConfig as $scopeCode => $scopeValues) {
            if (!empty($scopeValues['web']['unsecure']['base_url'])) {
                $domains[] = getDomain($scopeValues['web']['unsecure']['base_url']);
            }

            if (!empty($scopeValues['web']['secure']['base_url'])) {
                $domains[] = getDomain($scopeValues['web']['secure']['base_url']);
            }
        }

    }

    return array_unique($domains);
}

function getConfigsFromEnv($path, $configPaths) {

    $config = include $path . '/app/etc/env.php';

    $configsFromEnv = [];

    if (empty($config['system'])) {
        return $configsFromEnv;
    }

    $configPatcher = new ConfigPatcher($config);

    foreach ($configPaths as $configPath) {
        $configValue = $configPatcher->get('system/default/' . $configPath, '/');
        if ($configValue != null) {
            $configsFromEnv[] = [
                'scope' => 'default',
                'scope_code' => 'default',
                'path' => $configPath,
                'value' => $configValue,
                'source' => 'env'
            ];
        }

        foreach ($config['system'] as $scopeType => $data) {
            if ($scopeType === 'default') {
                continue;
            }

            foreach ($data as $scopeCode => $scopeConfig) {
                $configValue = $configPatcher->get("system/{$scopeType}/{$scopeCode}/{$configPath}", '/');
                if ($configValue != null) {
                    $configsFromEnv[] = [
                        'scope' => $scopeType,
                        'scope_code' => $scopeCode,
                        'path' => $configPath,
                        'value' => $configValue,
                        'source' => 'env'
                    ];
                }
            }
        }
    }

    return $configsFromEnv;
}

function createBackupTable(DBConnection $db, $tableName) {
    if ($db->isTableExists($tableName . '_autobackup')) {
        //throw new \Exception('Table ' . $tableName . '_autobackup already exists!');
        echo 'Table ' . $tableName . '_autobackup already exists! Removing!' . PHP_EOL;

        $db->query('DROP TABLE ' . $tableName . '_autobackup');
    }
    // make dump of original table:
    $db->query('CREATE TABLE '.$tableName.'_autobackup LIKE '.$tableName);
    $db->query('INSERT INTO '.$tableName.'_autobackup SELECT * FROM '.$tableName);
}


function restoreTableFromBackup(DBConnection $db, $tableName)
{
    if (!$db->isTableExists($tableName)) {
        throw new \Exception('Table ' . $tableName . ' not exist!');
    }
    $db->query("DELETE FROM {$tableName}");
    $db->query('INSERT INTO '.$tableName.' SELECT * FROM ' . $tableName . '_autobackup');
    $db->query('DROP TABLE ' . $tableName . '_autobackup');
}

function dbRevert($path) {
    echo "REVERTING db values..." . PHP_EOL;
    $envConfig = include $path . '/app/etc/env.php';
    $db = new DBConnection($envConfig);

    restoreTableFromBackup($db, 'core_config_data');
}

function replaceDb($path) {

    echo "Replacing db values..." . PHP_EOL;

    $envConfig = include $path . '/app/etc/env.php';
    $configPatcher = new ConfigPatcher($envConfig);

    $db = new DBConnection($envConfig);
    // make dump of original table:
    createBackupTable($db, 'core_config_data');


    $domainPatches = [
        'web/unsecure/base_url',
        'web/secure/base_url',
        'web/unsecure/base_link_url',
        'web/secure/base_link_url',
        'web/unsecure/base_media_url',
        'web/secure/base_media_url',
        'web/unsecure/base_static_url',
        'web/secure/base_static_url',
        'web/cookie/cookie_domain',
        'admin/url/custom',
    ];
    $patchWhere = implode(',', array_map(static function($value) {return "'{$value}'";}, $domainPatches));

    $configsFromEnv = getConfigsFromEnv($path, $domainPatches);

    $configsFromDb = $db->getAllRows("SELECT
       CASE
        when c.scope = 'websites' THEN w.code
        WHEN c.scope = 'stores' THEN s.code
        ELSE 'default'
        END AS scope_code,
       c.*,
       'db' as `source`
FROM core_config_data as c
    LEFT JOIN store_website as w ON c.scope_id = w.website_id AND c.scope = 'websites'
    LEFT JOIN store as s ON c.scope_id = s.store_id AND c.scope = 'stores'
WHERE c.path IN({$patchWhere})");


    $configs = array_merge($configsFromEnv, $configsFromDb);


//$stores = $db->getAllRows("SELECT * FROM store", 'store_id');
//$websites = $db->getAllRows("SELECT * FROM store_website", 'website_id');

    $settingsObject = new DotEnv($path . '/.env');
    $settings = $settingsObject->get();
    $replaceDomain = $settings['TRAEFIK_DOMAIN'];
    $currentReplace = "https://{$settings['TRAEFIK_SUBDOMAIN']}.{$replaceDomain}/";

    $scopes = [];

    $uniqueDomains = [];

    $updates = [];
    foreach ($configs as $config) {
        $fullPatch = 'system/' . $config['scope'] . '/' . ($config['scope_code'] === 'default' ? '' : $config['scope_code'] . '/') . $config['path'];

        // if config already exists in env file
        if ($config['source'] === 'db' && $configPatcher->exists($fullPatch, '/')) {
            continue;
        }

        if (empty($config['value'])) {
            continue;
        }

        $domain = getDomain($config['value']);
        if (!$domain || strpos($domain, '{{') === 0) {
            continue;
        }

        if (isset($uniqueDomains[$domain]) && $config['scope'] !== 'default') {
            continue;
        }


        if ($config['scope'] === 'default') {
            $newDomain = 'app.' . $replaceDomain;
        } else {

            $newDomain = explode('.', $domain);
            if (!empty($newDomain[0]) && $newDomain[0] === 'www') {
                array_shift($newDomain);
            }
            array_pop($newDomain);
            $newDomain = implode('.', $newDomain);
            $newDomain = str_replace('.', '-', $newDomain) . '.' . $replaceDomain;
        }

        $uniqueDomains[$domain] = $newDomain;
    }
    $db->query("UPDATE core_config_data SET value = 'admin' WHERE path = 'admin/url/custom_path'");
    $db->query("UPDATE core_config_data SET value = NULL WHERE path = 'recaptcha_backend/type_for/user_login'");
    $db->query("UPDATE core_config_data SET value = '2' WHERE path = 'system/full_page_cache/caching_application'");
    $db->query("UPDATE core_config_data SET value = 'varnish' WHERE path = 'system/full_page_cache/varnish/backend_host'");
    $db->query("UPDATE core_config_data SET value = '80' WHERE path = 'system/full_page_cache/varnish/backend_port'");
    $db->query("UPDATE core_config_data SET value = 'elasticsearch' WHERE path = 'catalog/search/elasticsearch7_server_hostname'");
    $db->query("UPDATE core_config_data SET value = 'elasticsearch' WHERE path = 'catalog/search/elasticsearch8_server_hostname'");
    $db->query("UPDATE core_config_data SET value = 'opensearch' WHERE path = 'catalog/search/opensearch_server_hostname'");
    $db->query("UPDATE core_config_data SET value = '" . $_ENV['SEARCH_ENGINE'] . "' WHERE path = 'amasty_elastic/connection/server_hostname'");
    $db->query("UPDATE core_config_data SET value = '9200' WHERE path = 'catalog/search/elasticsearch7_server_port'");
    $db->query("UPDATE core_config_data SET value = '9200' WHERE path = 'catalog/search/elasticsearch8_server_port'");
    $db->query("UPDATE core_config_data SET value = '9200' WHERE path = 'catalog/search/opensearch_server_port'");
    $db->query("UPDATE core_config_data SET value = '9200' WHERE path = 'amasty_elastic/connection/server_port'");
    $db->query("UPDATE core_config_data SET value = '" . $_ENV['SEARCH_ENGINE'] . "' WHERE path = 'smile_elasticsuite_core_base_settings/es_client/servers'");
    $db->query("UPDATE core_config_data SET value = 'X-Forwarded-Proto' WHERE path = 'web/secure/offloader_header'");


    $magentoVarsContent = "";
    if (is_file($path . '/magento-vars.php')) {
        echo "Found magento-vars.php file" . PHP_EOL;
        $magentoVarsContent = file_get_contents($path . '/magento-vars.php');
    }

    $envContent = file_get_contents($path . '/app/etc/env.php');
    echo "Domains found and replaced" . PHP_EOL;

    printTable($uniqueDomains, ["Domain", "Replacement"]);
    foreach ($uniqueDomains as $domain => $replacement) {
        $db->query("UPDATE core_config_data SET value = REPLACE(value, '{$domain}', '{$replacement}') WHERE path IN({$patchWhere})");
        $magentoVarsContent = str_replace($domain, $replacement, $magentoVarsContent);
        $envContent = str_replace($domain, $replacement, $envContent);
    }

    if ($magentoVarsContent) {
        echo "Saved magento-vars-local.php file for multiple domains" . PHP_EOL;
        echo "Please include magento-vars-local.php into pub/index.php" . PHP_EOL;
        file_put_contents($path . '/magento-vars-local.php', $magentoVarsContent);
    }

    file_put_contents($path . '/app/etc/env.php', $envContent);

    if (!empty($uniqueDomains)) {
        echo PHP_EOL . PHP_EOL . "=========================" . PHP_EOL;
        $domains = implode(" ", $uniqueDomains);
        echo 'HOSTS_COMMAND=echo "127.0.0.1 '.$domains.'" | sudo tee -a /etc/hosts';
        echo PHP_EOL . "=========================" . PHP_EOL;
    }
}

function envRevert ($path)
{
    $envPath = $path . '/app/etc/env.php';
    $autobackupPath = $envPath . '.autobackup';

    if (!is_file($autobackupPath)) {
        echo "\r\n";
        echo ('Incorrect run directory, it should be home magento directory. Unable to locate env.php.autobackup ' . $autobackupPath . "\r\n");

        return;
    }

    unlink($envPath);
    rename($autobackupPath, $envPath);
}

function configRevert($path) {
    $filePath = $path . '/app/etc/config.php';
    $autobackupPath = $filePath . '.autobackup';

    if (!is_file($autobackupPath)) {
        echo "\r\n";
        echo ('Incorrect run directory, it should be home magento directory. Unable to locate config.php.autobackup ' . $autobackupPath . "\r\n");

        return;
    }

    unlink($filePath);
    rename($autobackupPath, $filePath);
}

function replaceConfigPhp($path) {
    echo "Replacing app/etc/config.php" . PHP_EOL;

    $filePath = $path . '/app/etc/config.php';
    $autobackupPath = $filePath . '.autobackup';

    if (!is_file($filePath)) {
        echo "\r\n";
        echo ('Incorrect run directory, it should be home magento directory. Unable to locate config.php ' . $filePath . "\r\n");

        return;
    }

    if (is_file($autobackupPath)) {
        echo "\r\n";
        echo ('Autobackup already exists! Reverting back' . $autobackupPath . "\r\n");
        configRevert($path);
        //return;
    }

    copy($filePath, $autobackupPath);

    $config = include $filePath;
    $configPatcher = new ConfigPatcher($config);

    $configPatcher->replaceIfExists('system.default.admin.url.custom_path', 'admin');
    $configPatcher->replaceIfExists('system.default.system.security.max_session_size_admin', '1024000');

    $config = $configPatcher->getConfigArray();

    file_put_contents($filePath, "<?php\r\nreturn " . varexport($config, true) . ';');
}
function replaceEnvConfig($path) {

    echo "Replacing app/etc/env.php" . PHP_EOL;

    $envPath = $path . '/app/etc/env.php';
    $autobackupPath = $envPath . '.autobackup';
    if (!is_file($envPath)) {
        echo "\r\n";
        echo ('Incorrect run directory, it should be home magento directory. Unable to locate env.php ' . $envPath . "\r\n");

        return;
    }

    if (is_file($autobackupPath)) {
        echo "\r\n";
        echo ('Autobackup already exists! Reverting back ' . $autobackupPath . "\r\n");

        envRevert($path);
    }

    copy($envPath, $autobackupPath);
    $config = include $envPath;



    $configPatcher = new ConfigPatcher($config);
    $configPatcher->replaceIfExists('backend.frontName', 'admin');

    $configPatcher->replaceIfExists('system.default.admin.url.custom_path', 'admin');

    $db = [
        'host' => 'db',
        'username' => 'magento',
        'dbname' => 'magento',
        'password' => 'magento',
    ];


    $configPatcher->replaceIfExists('db.connection.default', $db);
    $configPatcher->replaceIfExists('db.connection.indexer', $db);

    $configPatcher->replaceIfExists('queue.amqp', [
        'host' => 'rabbitmq',
        'port' => '5672',
        'user' => 'guest',
        'password' => 'guest',
        'virtualhost' => '/'
    ]);
    $configPatcher->replaceIfExists(
        'lock.config.path',
        '/var/www/html/' . ltrim($config['lock']['config']['path'] ?? '', '/')
    );

    $redisSettings = [
        'server' => 'redis',
        'port' => '6379',
        'password' => ''
    ];
    if ($configPatcher->exists('cache.frontend.default.backend_options.remote_backend_options')) {
        $configPatcher->replaceIfExists('cache.frontend.default.backend_options.remote_backend_options', $redisSettings);
    } else {
        $configPatcher->replaceIfExists('cache.frontend.default.backend_options', $redisSettings);
    }
    $configPatcher->replaceIfExists('cache.frontend.page_cache.backend_options', $redisSettings);
    $configPatcher->replaceIfExists('system.default.smile_elasticsuite_core_base_settings.es_client.servers', $_ENV['SEARCH_ENGINE'] . ':9200');
    $configPatcher->replaceIfExists('system.default.catalog.search.elasticsearch7_server_hostname', 'elasticsearch');
    $configPatcher->replaceIfExists('system.default.catalog.search.elasticsearch8_server_hostname', 'elasticsearch');
    $configPatcher->replaceIfExists('system.default.catalog.search.opensearch_server_hostname', 'opensearch');
    $configPatcher->replaceIfExists('system.default.system.security.max_session_size_admin', '1024000');
    $configPatcher->replaceIfExists('system.default.system.full_page_cache.varnish.backend_port', '80');
    $configPatcher->replaceIfExists('system.default.system.full_page_cache.varnish.backend_host', 'varnish');
    $configPatcher->replaceIfExists('system.default.web.secure.offloader_header', 'X-Forwarded-Proto');

    // $db->query("UPDATE core_config_data SET value = 'X-Forwarded-Proto' WHERE path = 'web/secure/offloader_header'");

    $config = $configPatcher->getConfigArray();

    if (isset($config['session']['save']) && $config['session']['save'] === 'redis') {
        $config['session']['redis']['host'] = 'redis';
        $config['session']['redis']['port'] = '6379';
    }

    unset($config['db']['slave_connection']);
    unset($config['cache']['frontend']['default']['backend_options']['load_from_slave']);
    unset($config['cache']['frontend']['page_cache']['backend_options']['load_from_slave']);
    unset($config['cache']['frontend']['default']['backend_options']['remote_backend_options']['load_from_slave']);
    unset($config['cache']['frontend']['page_cache']['backend_options']['remote_backend_options']['load_from_slave']);

    foreach ($config['db']['connection'] as $name => &$connectionConfig) {
        if ($connectionConfig['host'] !== $db['host']) {
            $connectionConfig['host'] = $db['host'];
            $connectionConfig['username'] = $db['username'];
            $connectionConfig['password'] = $db['password'];
        }
    }

    $config['http_cache_hosts'] = [
        [
          'host' => 'varnish',
          'port' => '80'
        ]
    ];
    file_put_contents($envPath, "<?php\r\nreturn " . varexport($config, true) . ';');
}

function varexport($expression, $return=FALSE) {
    $export = var_export($expression, TRUE);
    $patterns = [
        "/array \(/" => '[',
        "/^([ ]*)\)(,?)$/m" => '$1]$2',
        "/=>[ ]?\n[ ]+\[/" => '=> [',
        "/([ ]*)(\'[^\']+\') => ([\[\'])/" => '$1$2 => $3',
    ];
    $export = preg_replace(array_keys($patterns), array_values($patterns), $export);
    if ((bool)$return) return $export; else echo $export;
}

class ConfigPatcher
{
    /**
     * @var array
     */
    private $config;

    public function __construct(array $config) {

        $this->config = $config;
    }

    public function exists($path, $separator = '.') {
        $keys = explode($separator, $path);

        $current = $this->config;
        foreach ($keys as $key) {

            if (!array_key_exists($key, $current)) {
                return false;
            }

            $current = $current[$key];
        }

        return true;
    }

    public function replaceIfExists($path, $replacement)
    {
//        if (!$this->exists($path)) {
//            return false;
//        }

        $keys = explode('.', $path);
        $current = &$this->config;
        foreach ($keys as $key) {

            if (!array_key_exists($key, $current)) {
                return false;
            }

            $current = &$current[$key];
        }

        if (is_array($replacement)) {
            foreach ($replacement as $key => $value) {
                $current[$key] = $value;
            }
        } else {
            $current = $replacement;
        }

//        if (is_array($replacement)) {
//            foreach ($replacement as $key => $value) {
//                $this->replaceIfExists($path . '.' . $key, $value);
//            }
//        } else {
//            $keys = explode('.', $path);
//
//            $current = &$this->config;
//            foreach ($keys as $key) {
//
//                if (!array_key_exists($key, $current)) {
//                    return false;
//                }
//
//                $current = &$current[$key];
//            }
//            $current = $replacement;
//        }

        return true;
    }

    public function getConfigArray(): array
    {
        return $this->config;
    }

    public function get($path, $separator)
    {
        $keys = explode($separator, $path);

        $value = $this->config;
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    public function remove($path) {

    }
}

function getDomain($string) {

    $domain = str_replace(['http://', 'https://'], '', $string);

    $domain = explode('/', $domain);

    return $domain[0];
}

function printTable($keyValue, $headers) {
    $maxLength = 0;
    foreach ($keyValue as $key => $value) {
        $keyLen = strlen($key);
        $valLen = strlen($value);
        $maxLength = max($maxLength, $keyLen, $valLen);
    }

    $alignment = $maxLength+4;
    echo str_pad("", $alignment, "-") . '-' , str_pad("", $alignment, "-") . PHP_EOL;
    echo "|" . str_pad($headers[0], $alignment, " ", STR_PAD_BOTH) . '|' . str_pad($headers[1], $alignment, " ", STR_PAD_BOTH) . '|' . PHP_EOL;

    echo str_pad("", $alignment, "-") . '-' , str_pad("", $alignment, "-") . PHP_EOL;
    foreach ($keyValue as $key => $value) {
        echo '|' . str_pad("{$key}", $alignment, " ", STR_PAD_BOTH) . "|".str_pad("{$value}", $alignment, " ", STR_PAD_BOTH) . '|'. PHP_EOL;
    }
    echo str_pad("", $alignment, "-") . '-' , str_pad("", $alignment, "-") . PHP_EOL;
}

class DBConnection
{
    private $pdo;

    private $dbName;

    public function __construct(array $config, $connectionName = 'default')
    {
        $dbConfig = $config['db']['connection'][$connectionName];

        $this->dbName = $dbConfig['dbname'];
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    public function query($sql) {
        return $this->pdo->query($sql);
    }

    public function fetchOne($sql)
    {
        $stmt = $this->query($sql);

        return $stmt->fetchColumn();
    }

    public function isTableExists($tableName)
    {
        $sql = "SELECT COUNT(TABLE_NAME) as cnt
FROM
    information_schema.TABLES
WHERE
        TABLE_SCHEMA LIKE '{$this->dbName}' AND
        TABLE_TYPE LIKE 'BASE TABLE' AND
        TABLE_NAME = '{$tableName}'";

        $count = $this->fetchOne($sql);

        return $count > 0;
    }


    public function getAllRows($sql, $indexColumn = null)
    {
        $stmt = $this->pdo->query($sql);
        if ($indexColumn === null) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = $row[$indexColumn];
            $rows[$id] = $row;
        }

        return $rows;
    }
}

class DotEnv
{
    /**
     * The directory where the .env file can be located.
     *
     * @var string
     */
    protected $path;


    public function __construct($path)
    {
        if(!file_exists($path)) {
            throw new \InvalidArgumentException(sprintf('%s does not exist', $path));
        }
        $this->path = $path;
    }

    public function get()
    {
        if (!is_readable($this->path)) {
            throw new \RuntimeException(sprintf('%s file is not readable', $this->path));
        }

        $envVariables = [];
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {

            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            $envVariables[$name] = $value;
        }

        return $envVariables;
    }
}
