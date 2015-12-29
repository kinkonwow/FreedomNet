<?php
namespace Core\Extensions;
use Core\Libraries\FreedomCore\System\Database as Database;
use Core\Libraries\FreedomCore\System\Text as Text;
use Core\Libraries\FreedomCore\System\File as File;
use \PDO as PDO;

class Installer {

    /**
     * Smarty Template Manager Variable
     * @var
     */
    private $TM;

    /**
     * Reference to DatabaseManager Class
     * @var
     */
    private $DBManager;

    /**
     * Website Connection PDO Object
     * @var
     */
    private $Connection = null;

    /**
     * Installer Class Constructor
     * @param null $TemplatesManager
     */
    public function _construct($TemplatesManager = null){
        if($TemplatesManager != null)
            $this->TM = $TemplatesManager;
    }

    /**
     * Get Installer Version Based On Github Commit Hash
     * @return string
     */
    public function getInstallerVersion(){
        $GitHead = getcwd().DS.'.git'.DS.'FETCH_HEAD';
        if(file_exists($GitHead)){
            $LocalVersion = file_get_contents($GitHead);
            list($LocalVersion, $ServiceInfo) = explode('branch', $LocalVersion);
        } else {
            $LocalVersion = "Undefined";
        }
        return $LocalVersion;
    }

    /**
     * Get Response From Github API Server
     * @param $APIUrl
     * @return mixed
     */
    private function getGithubResponse($APIUrl)
    {
        $Curl = curl_init();
        curl_setopt($Curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($Curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($Curl, CURLOPT_USERAGENT, 'FreedomCore Installer v2.0');
        curl_setopt($Curl, CURLOPT_URL, $APIUrl);
        $Result = curl_exec($Curl);
        curl_close($Curl);
        return $Result;
    }

    /**
     * Write Collected Data To File
     * @param $Folder
     * @param $FileName
     * @param array $Data
     */
    private function writeToFile($Folder, $FileName, $Data = array())
    {
        $FileHandler = fopen($Folder.DS.$FileName, 'w');
        foreach($Data as $Key => $Value){
            fwrite($FileHandler, $Key . " = " .$Value ."\n");
        }
        fclose($FileHandler);
    }

    /**
     * Create Database Configuration File
     * @param $Request
     * @return string
     */
    public function createDatabaseFile($Request){
        $ServerFolder = getcwd();
        $InstallationFolder = $ServerFolder.DS.'Install';
        $ConfigurationFolder = $InstallationFolder.DS.'configuration';
        unset($Request['action']);
        $PatchData = $this->getPatchMatch($Request['game_patch']);
        $FileName = Text::generateRandomString(15).'_'.$PatchData['short'].'.json';
        $Request['patch_name'] = $PatchData['full'];
        $Request['patch_real'] = $PatchData['real'];

        $FileHandler = fopen($ConfigurationFolder.DS.$FileName, 'w');
        fwrite($FileHandler, json_encode($Request, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fclose($FileHandler);

        return '1';
    }

    /**
     * Create Website Configuration File
     * @param $Request
     * @return string
     */
    public function createWebsiteFile($Request){
        $ServerFolder = getcwd();
        $InstallationFolder = $ServerFolder.DS.'Install';
        $ConfigurationFolder = $InstallationFolder.DS.'configuration';
        unset($Request['action']);
        $FileName = Text::generateRandomString(15).'_website.json';
        $FileHandler = fopen($ConfigurationFolder.DS.$FileName, 'w');
        fwrite($FileHandler, json_encode($Request, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fclose($FileHandler);

        return '1';
    }

    /**
     * Get All Database Files
     * @return string
     */
    public function getDatabases(){
        $ServerFolder = getcwd();
        $InstallationFolder = $ServerFolder.DS.'Install';
        $ConfigurationFolder = $InstallationFolder.DS.'configuration';
        $Configurations = [];
        foreach(glob($ConfigurationFolder.'/*.json') as $file)
            $Configurations[] = json_decode(file_get_contents($file), true);

        return json_encode($Configurations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Check if all required modules installed
     * @return array
     */
    public function checkPHPModules()
    {
        $ModulesArray = array(
            array(
                'name' => 'pdo_mysql',
                'status' => extension_loaded('pdo_mysql')
            ),
            array(
                'name' => 'curl',
                'status' => extension_loaded('curl')
            ),
            array(
                'name' => 'mysqli',
                'status' => extension_loaded('mysqli')
            ),
            array(
                'name' => 'soap',
                'status' => extension_loaded('soap')
            ),
            array(
                'name' => 'gd',
                'status' => extension_loaded('gd')
            ),
            array(
                'name' => 'soap',
                'status' => extension_loaded('soap')
            ),
        );

        return $ModulesArray;
    }

    /**
     * Get Server Info
     * @return array
     */
    public function getServerInfo()
    {
        $ServerData = [];
        $ServerData['Server'] = $_SERVER["SERVER_SOFTWARE"];
        $ServerData['PHP'] = $this->getPHPInfo();
        $ServerData['MySQL'] = $this->getMySQLInfo();
        if(strstr($_SERVER['SERVER_SOFTWARE'], 'Apache')){
            $ServerData['Apache'] = $this->getApacheInfo();
            $ServerData['TestState'] = "VALIDATION_STATE_TESTED";
        } else
            $ServerData['TestState'] = "VALIDATION_STATE_UNTESTED";
        $ServerData['OS'] = $this->getOSInfo();


        if( $ServerData['PHP']['valid'] == 'VALIDATION_STATE_PASSED' || $ServerData['PHP']['valid'] == 'VALIDATION_STATE_WARNING' &&
            $ServerData['MySQL']['valid'] == 'VALIDATION_STATE_PASSED' || $ServerData['MySQL']['valid'] == 'VALIDATION_STATE_WARNING' &&
            $ServerData['OS']['valid'] == 'VALIDATION_STATE_PASSED' || $ServerData['OS']['valid'] == 'VALIDATION_STATE_WARNING' )
            $ServerData['AllowInstallation'] = "YES";
        else
            $ServerData['AllowInstallation'] = "NO";
        return $ServerData;
    }

    /**
     * Process Received Data From Parsing JSON and $_REQUEST Variable
     * @param $Databases
     * @param $SiteData
     */
    public function processInput($Databases, $SiteData){
        $Configuration = '<?php'.PHP_EOL;
        $Configuration .= 'global $FCCore;'.PHP_EOL.PHP_EOL;
        $WebsiteConfiguration = "";
        $PatchesConfiguration = [];
        $SocialConfiguration = [];
        $AnalyticsConfiguration = [];
        $SiteConfiguration = [];
        foreach($Databases as $Database)
            if(count($Database) == 6)
                $WebsiteConfiguration = $this->processWebsiteDatabase($Database);
            else {
                $Parsed = $this->processGameDatabase($Database, $PatchesConfiguration);
                $PatchesConfiguration[$Parsed['Key']] = $Parsed['Config'];
            }

        $Configuration .= $WebsiteConfiguration;
        foreach($PatchesConfiguration as $PatchDB)
            $Configuration .= $PatchDB;

        foreach($SiteData as $Key=>$Value){
            if(strstr($Key, '_link') || strstr($Key, 'skype'))
                $SocialConfiguration[] = $this->processSocialData($Key, $Value, $SocialConfiguration);
            if(strstr($Key, 'ga_'))
                $AnalyticsConfiguration[] = $this->processAnalyticsData($Key, $Value, $AnalyticsConfiguration);
            if(strstr($Key, 'site_'))
                $SiteConfiguration[] = $this->processSiteData($Key, $Value);
        }

        foreach($SocialConfiguration as $Social)
            $Configuration .= $Social;
        foreach($AnalyticsConfiguration as $Analytics)
            $Configuration .= $Analytics;

        $Configuration .= PHP_EOL.'// Facebook Settings'.PHP_EOL;
        foreach($this->processFABlock() as $IKey => $IVal)
            $Configuration .= '$FCCore[\'Facebook\'][\''.$IKey.'\'] = \''.$IVal.'\';'.PHP_EOL;

        $Configuration .= PHP_EOL.'// Site Configuration'.PHP_EOL;
        foreach($SiteConfiguration as $Item)
            $Configuration .= $Item;
        foreach($this->processBasicConfiguration() as $IKey => $IVal){
            if($IVal === false)
                $Configuration .= '$FCCore[\''.$IKey.'\'] = false;'.PHP_EOL;
            elseif($IVal === true)
                $Configuration .= '$FCCore[\''.$IKey.'\'] = true;'.PHP_EOL;
            else
                $Configuration .= '$FCCore[\''.$IKey.'\'] = \''.$IVal.'\';'.PHP_EOL;
        }

        $Configuration .= PHP_EOL."?>";
        $ConfigurationFile = getcwd().DS.'Core'.DS.'Configuration'.DS.'Configuration.php';
        if(File::Exists($ConfigurationFile)){
            unlink($ConfigurationFile);
            file_put_contents($ConfigurationFile, $Configuration);
        } else {
            file_put_contents($ConfigurationFile, $Configuration);
        }
    }

    /**
     * Get Repositories From Github
     * @return mixed
     */
    public function getGithubRepoStatus()
    {
        $ServerFolder = getcwd();
        $InstallationFolder = $ServerFolder.DS.'Install';
        $FileName = md5(uniqid(rand(), true)).".github-data";
        $RepoURL = "https://api.github.com/repos/darki73/FreedomNet";

        if($this->isGithubStatusFileExists($InstallationFolder)){

        } else {
            $Data = json_decode($this->getGithubResponse($RepoURL), true);
            $FreedomCoreRepo['name']            =   $Data['name'];
            $FreedomCoreRepo['stargazers']      =   $Data['stargazers_count'];
            $FreedomCoreRepo['watchers']        =   $Data['watchers_count'];
            $FreedomCoreRepo['language']        =   $Data['language'];
            $FreedomCoreRepo['forks']           =   $Data['forks_count'];
            $FreedomCoreRepo['size']            =   $Data['size'];
            $FreedomCoreRepo['forks_url']       =   $Data['forks_url'];
            $FreedomCoreRepo['stargazers_url']  =   $Data['stargazers_url'];
            $FreedomCoreRepo['url']             =   $Data['html_url'];
            $FreedomCoreRepo['last_update']     =   $Data['updated_at'];

            $BranchesURL = "https://api.github.com/repos/darki73/".$FreedomCoreRepo['name']."/branches";
            $Branches = json_decode($this->getGithubResponse($BranchesURL), true);
            $Commit = "";
            for($i = 0; $i <= count($Branches); $i++){
                if($i == 0){
                    $Commit = $Branches[$i]['commit']['sha'];
                    break;
                }
            }
            $FreedomCoreRepo['commit'] = $Commit;

            $this->checkAndWrite($InstallationFolder, $FileName, $FreedomCoreRepo);
        }

        return $this->getGithubFileData($InstallationFolder);
    }

    /**
     * Set Database Manager
     * @param DatabaseManager $DBManager
     */
    public function assignDBManager(DatabaseManager $DBManager){
        $this->DBManager = $DBManager;
    }

    /**
     * Match Patch ID With Its Name
     * @param $PatchID
     * @return mixed
     */
    private function getPatchMatch($PatchID){
        $Patches = [
            1   =>  ['short' => 'classic', 'full' => 'Classic', 'real' => '0', 'dbname' => 'Classic'],
            2   =>  ['short' => 'tbc', 'full' => 'The Burning Crusade', 'real' => '1', 'dbname' => 'TBC'],
            3   =>  ['short' => 'wotlk', 'full' => 'Wrath of the Lich King', 'real' => '2', 'dbname' => 'WotLK'],
            4   =>  ['short' => 'cataclysm', 'full' => 'Cataclysm', 'real' => '3', 'dbname' => 'Cata'],
            5   =>  ['short' => 'mop', 'full' => 'Mists of Pandaria', 'real' => '4', 'dbname' => 'MOP'],
            6   =>  ['short' => 'draenor', 'full' => 'Warlords of Draenor', 'real' => '5', 'dbname' => 'WoD'],
            7   =>  ['short' => 'legion', 'full' => 'Legion', 'real' => '6', 'dbname' => 'Legion']
        ];
        return $Patches[$PatchID];
    }

    /**
     * Get Github Data File
     * @param $Folder
     * @return mixed
     */
    private function getGithubDataFileName($Folder) {
        $Files = array();
        foreach (glob($Folder.DS."*.github-data") as $File)
            $Files[] = $File;
        return $Files[0];
    }

    /**
     * Check If Github Data File Already Exists
     * @param $Folder
     * @return bool
     */
    private function isGithubStatusFileExists($Folder){
        $Files = array();
        foreach (glob($Folder.DS."*.github-data") as $File)
            $Files[] = $File;

        if(!empty($Files)){
            if(time() > (86400 + filemtime($Files[0])))
                return false;
            else
                return true;
        }
        else
            return false;
    }

    /**
     * Check if file exists and write data
     * @param $Folder
     * @param $Name
     * @param $Data
     */
    private function checkAndWrite($Folder, $Name, $Data)
    {
        $Files = array();
        foreach (glob($Folder.DS."*.github-data") as $File) {
            $Files[] = $File;
        }
        if(!empty($Files)){
            if(time() > (86400 + filemtime($Files[0]))){
                unlink($Files[0]);
                $this->writeToFile($Folder, $Name, $Data);
            }
        } else {
            $this->writeToFile($Folder, $Name, $Data);
        }
    }

    /**
     * Get Github Data File Contents
     * @param $Folder
     * @return array
     */
    private function getGithubFileData($Folder)
    {
        $Lines = file($this->getGithubDataFileName($Folder), FILE_IGNORE_NEW_LINES);
        $FreedomCoreRepo = [];
        foreach ($Lines as $Line){
            $Exploded = explode(' = ', $Line);
            $FreedomCoreRepo[$Exploded[0]] = $Exploded[1];
        }

        return $FreedomCoreRepo;
    }

    /**
     * Get PHP Version Installed
     * @return array
     */
    private function getPHPInfo()
    {
        $Required = '7.0.0';
        $Installed = phpversion();
        if(strstr($Installed, '-'))
            $Installed = substr($Installed, 0, strrpos($Installed, '-'));

        if(strlen($Installed) > 6){
            $InstalledT = substr($Installed, 0, -1);
            $InstalledR = str_replace('.', '', $InstalledT);
        } else {
            $InstalledR = str_replace('.', '', $Installed);
        }

        $RequiredR = str_replace('.', '', $Required);

        if($InstalledR >= $RequiredR)
            $Valid = "VALIDATION_STATE_PASSED";
        else
            $Valid = "VALIDATION_STATE_FAILED";

        return ['required' => $Required, 'installed' => $Installed, 'valid' => $Valid];
    }

    /**
     * Get MySQL Client Version Installed
     * @return array
     */
    private static function getMySQLInfo(){
        $Required = '5.0.11';
        $Installed = Database::ClientVersion();

        $RequiredR = str_replace('.', '', substr($Required, 0, 3));
        $InstalledR = str_replace('.', '', substr($Installed, 0, 3));

        if($InstalledR >= $RequiredR)
            $Valid = "VALIDATION_STATE_PASSED";
        else
            $Valid = "VALIDATION_STATE_FAILED";

        return ['required' => $Required, 'installed' => $Installed, 'valid' => $Valid];
    }

    /**
     * Get Apache Version Installed
     * @return array
     */
    private static function getApacheInfo()
    {
        $Version = apache_get_version();
        $Required = "2.2.29";
        $Installed = str_replace(' ', '', str_replace('Apache/', '', strstr($Version, '(', true)));

        if(strlen($Installed) < strlen($Required))
            for($i = 0; $i < (strlen($Required) - strlen($Installed)); $i++)
                $Installed .= "0";

        $RequiredR = str_replace('.', '', $Required);
        $InstalledR = str_replace('.', '', $Installed);

        if($InstalledR >= $RequiredR)
            $Valid = "VALIDATION_STATE_PASSED";
        else
            if($InstalledR == "000"){
                $Valid = "VALIDATION_STATE_WARNING";
                $Installed = "Hidden";
            }
            else
                $Valid = "VALIDATION_STATE_FAILED";

        return ['required' => $Required, 'installed' => $Installed, 'valid' => $Valid];
    }

    /**
     * Get OS Version
     * @return array
     */
    private static function getOSInfo()
    {
        $Required = "Windows / Linux";
        $TestedSystems = ['WINNT', 'LINUX'];
        $ShortName = PHP_OS;

        if(strtoupper($ShortName) == 'WINNT'){
            $Build = explode('build ', php_uname('v'))[1];
            $OSData = [
                'name'      =>  substr(php_uname('s'), 0, strrpos(php_uname('s'), ' ')),
                'version'   =>  php_uname('r'),
                'build'     =>  substr($Build, 0, strrpos($Build, ' ')),
                'arch'      =>  php_uname('m')
            ];
        } elseif (strtoupper($ShortName) == "LINUX") {
            $Build = explode('-', php_uname('r'));
            $OSData = [
                'name'      =>  php_uname('s'),
                'version'   =>  $Build[0],
                'build'     =>  $Build[0],
                'arch'      =>  php_uname('m'),
            ];
        } else {
            $OSData = [
                'name'      =>  "Mac OS X",
                'version'   =>  "Undefined",
                'build'     =>  "Undefined",
                'arch'      =>  "Undefined",
            ];
        }
        if(in_array(strtoupper($ShortName), $TestedSystems))
            $Valid = "VALIDATION_STATE_PASSED";
        else
            $Valid = "VALIDATION_STATE_WARNING";

        return ['required' => $Required, 'installed' => $OSData, 'valid' => $Valid];
    }

    /**
     * Process Website Database Configuration
     * @param $Request
     * @return string
     */
    private function processWebsiteDatabase($Request){
        $ConfigurationString = PHP_EOL.'// Main Database Configuration'.PHP_EOL;
        foreach($Request as $Key => $Value){
            $KeyName = str_replace('website', 'database', str_replace('database_', '', $Key));
            $ConfigurationString .= '$FCCore[\'Website\'][\'Database\'][\''.$KeyName.'\'] = \''.$Value.'\';'.PHP_EOL;
        }
        return $ConfigurationString;
    }

    /**
     * Process Games Database Configuration With Multi-Patch And Multi-Realm Support
     * @param $Data
     * @param $ExistingArray
     * @return array
     */
    private function processGameDatabase($Data, $ExistingArray){
        $Name = $this->getPatchMatch($Data['game_patch'])['dbname'];
        $KeyName = "";

        if(empty($ExistingArray)){
            $KeyName = $Name.'One';
        } else {
            foreach($ExistingArray as $Key=>$Value){
                $Reversed = strrev($Key);
                $Counter = strrev(substr($Reversed, 0, strcspn($Reversed, 'ABCDEFGHJIJKLMNOPQRSTUVWXYZ') + 1));
                if(array_key_exists($Name.$Counter, $ExistingArray)){
                    $intCounter = Text::convertTextToNumber($Counter);
                    $intCounter = $intCounter + 1;
                    $textCounter = Text::convertNumberToText($intCounter);
                    $KeyName = $Name.ucfirst($textCounter);
                } else {
                    $KeyName = $Name.'One';
                }
            }
        }

        $ConfigurationString = PHP_EOL.'// '.$KeyName.' Database Configuration'.PHP_EOL;
        $DBConfiguration = [];
        $SoapConfiguration = [];
        $SiteConfiguration = [];
        foreach($Data as $Key=>$Value){
            if(strstr($Key, 'database'))
                $DBConfiguration[str_replace('database_', '', $Key)] = $Value;
            elseif(strstr($Key, 'soap'))
                $SoapConfiguration[str_replace('soap_', '', $Key)] = $Value;
            else
                $SiteConfiguration[$Key] = $Value;
        }
        foreach($DBConfiguration as $Key=>$Value)
            $ConfigurationString .= '$FCCore[\''.$KeyName.'\'][\'Database\'][\''.$Key.'\'] = \''.$Value.'\';'.PHP_EOL;
        $ConfigurationString .= PHP_EOL;
        foreach($SoapConfiguration as $Key=>$Value)
            $ConfigurationString .= '$FCCore[\''.$KeyName.'\'][\'soap\'][\''.$Key.'\'] = \''.$Value.'\';'.PHP_EOL;
        $ConfigurationString .= PHP_EOL;
        foreach($SiteConfiguration as $Key=>$Value)
            $ConfigurationString .= '$FCCore[\''.$KeyName.'\'][\'site\'][\''.str_replace('site_', '', $Key).'\'] = \''.$Value.'\';'.PHP_EOL;

        return ['Key' => $KeyName, 'Config' => $ConfigurationString];
    }

    /**
     * Process Social Block Configuration
     * @param $Key
     * @param $Value
     * @param $SocialArray
     * @return string
     */
    private function processSocialData($Key, $Value, $SocialArray){
        $ConfigurationString = '';
        $KeyName = ucfirst(str_replace('_username', '', str_replace('_link', '', $Key)));
        if(empty($SocialArray))
            $ConfigurationString .= PHP_EOL.'// Social Media Links'.PHP_EOL;
        $ConfigurationString .= '$FCCore[\'Social\'][\''.$KeyName.'\'] = \''.$Value.'\';'.PHP_EOL;
        return $ConfigurationString;
    }

    /**
     * Process Website Data
     * @param $Key
     * @param $Value
     * @return string
     */
    private function processSiteData($Key, $Value){
        $Key = str_replace('site_', '', $Key);
        if($Key == 'title')
            $KeyName = 'ApplicationName';
        elseif($Key == 'description')
            $KeyName = 'ApplicationDescription';
        elseif($Key == 'keywords')
            $KeyName = 'ApplicationKeywords';

        $ConfigurationString = '$FCCore[\''.$KeyName.'\'] = \''.$Value.'\';'.PHP_EOL;
        return $ConfigurationString;
    }

    /**
     * Process Google Analytics Configuration Data
     * @param $Key
     * @param $Value
     * @param $AnalyticsArray
     * @return string
     */
    private function processAnalyticsData($Key, $Value, $AnalyticsArray){
        $ConfigurationString = '';
        $KeyName = ucfirst(str_replace('ga_', '', $Key));
        if(empty($AnalyticsArray))
            $ConfigurationString .= PHP_EOL.'// Google Analytics Configuration'.PHP_EOL;
        $ConfigurationString .= '$FCCore[\'GoogleAnalytics\'][\''.$KeyName.'\'] = \''.$Value.'\';'.PHP_EOL;
        return $ConfigurationString;
    }

    /**
     * Default Globals For Website
     * @return array
     */
    private function processBasicConfiguration(){
        $Globals = [
            "TimeZone"			=>	"Europe/Moscow",
            "SmartyCaching"	    =>	false,
            "SmartyDebug"		=>	false,
            "Caching"			=>	false,
            "debug"			    =>	true,
            "email"			    =>	"",
            "registration"		=>	false,
            "Template"          =>  "FreedomCore",
        ];
        return $Globals;
    }

    /**
     * Only Configurable Through Configuration.php | Facebook Admins Page Block
     * @return array
     */
    private  function processFABlock(){
        $Facebook = [
            'admins'    =>  '',
            'pageid'    =>  '',
        ];
        return $Facebook;
    }

    /**
     * Connect to Website Database
     */
    public function setWebsiteDatabase(){
        $Databases = json_decode($this->getDatabases(), true);
        foreach($Databases as $Key=>$Value) {
            if (count($Value) == 6) {
                try {
                    $this->Connection = new PDO(
                        "mysql:host=" . $Databases[$Key]['database_host'] . ";
                        dbname=" . $Databases[$Key]['database_website'] . ";
                        charset=" . $Databases[$Key]['database_encoding'],
                        $Databases[$Key]['database_username'],
                        $Databases[$Key]['database_password'],
                        [PDO::ATTR_PERSISTENT => false]
                    );
                } catch (PDOException $e) {
                    $ExceptionMessage = "<strong>Database Connection Exception Occurred</strong>" . PHP_NL;
                    $ExceptionMessage .= "<strong>Error Code:</strong> " . $e->getCode() . PHP_NL;
                    $ExceptionMessage .= "<strong>Error Message:</strong> " . $e->getMessage() . PHP_NL;
                    $ExceptionMessage .= "<strong>Error File:</strong> " . $e->getFile() . PHP_NL;
                    $ExceptionMessage .= "<strong>Error Line:</strong> " . $e->getLine() . PHP_NL;
                    $ExceptionMessage .= "<i>Error occurred during initiation of the connection to </i><strong>" . $Databases[$Key]['database_website'] . "</strong><i> database</i>";
                    die($ExceptionMessage);
                };
            }
        }
    }

    public function buildInitialTables(){
        //Database::plainSQLPDO($this->Connection, $this->buildUsersTable());
        echo "<pre>";
        //echo $this->buildUsersTable()->prettify();
        //echo $this->buildApiAndroidArmoryTable()->prettify();
        //echo $this->buildAPIKeysTable()->prettify();
        //echo $this->buildClassAbilitiesTable()->prettify();
        //echo $this->buildClassesTable()->prettify();
        //echo $this->buildCommentsTable()->prettify();
        echo $this->buildDBVersionTable()->prettify();
    }

    /**
     * Create Users Table SQL
     * @return mixed
     */
    private function buildUsersTable(){
        return $this->DBManager->setTableName('users')
            ->addColumn('id', 'int', 11, false, true)
            ->addColumn('username', 'varchar', 45, true, false, true)
            ->addColumn('password', 'varchar', 50, true, false, true)
            ->addColumn('email', 'varchar', 45, true, false, true)
            ->addColumn('registration_date', 'datetime', false, true, false, true)
            ->addColumn('pinned_character', 'int', 11, true, false, true)
            ->addColumn('freedomtag_name', 'varchar', 45, true, false, true)
            ->addColumn('freedomtag_id', 'int', 11, true, false, true)
            ->addColumn('balance', 'float', true, false, 0)
            ->addColumn('selected_currency', 'varchar', 6, true, false, 'USD')
            ->addColumn('access_level', 'int', 2, false, false, 0)
            ->build();
    }

    /**
     * Create API Android Armory Table
     * @return mixed
     */
    private function buildApiAndroidArmoryTable(){
        return $this->DBManager->setTableName('api_android_armory')
            ->addColumn('id', 'int', 11, false, true)
            ->addColumn('username', 'varchar', 45, true, false, true)
            ->addColumn('password', 'varchar', 100, true, false, true)
            ->addColumn('armory_key', 'varchar', 120, true, false, true)
            ->addColumn('authorized', 'int', 11, true, false, true)
            ->build();
    }

    /**
     * Create API Keys Table
     * @return mixed
     */
    private function buildAPIKeysTable(){
        return $this->DBManager->setTableName('api_keys')
            ->addColumn('id', 'int', 11, false, true)
            ->addColumn('username', 'varchar', 45, true, false, true)
            ->addColumn('api_key', 'varchar', 60, true, false, true)
            ->addColumn('rps', 'int', 11, true, false, 5)
            ->addColumn('rpm', 'int', 11, true, false, 300)
            ->addColumn('rph', 'int', 11, true, false, 15000)
            ->addColumn('active', 'int', 11, true, false, 1)
            ->build();
    }

    /**
     * Create Class Abilities Table
     * @return mixed
     */
    private function buildClassAbilitiesTable(){
        return $this->DBManager->setTableName('classabilities')
            ->addColumn('id', 'int', 11, false, true)
            ->addColumn('class_name', 'varchar', 45, true, false, true)
            ->addColumn('ability_name', 'varchar', 45, true, false, true)
            ->addColumn('ability_description', 'varchar', 45, true, false, true)
            ->addColumn('ability_icon', 'varchar', 45, true, false, true)
            ->build();
    }

    /**
     * Create Classes Table
     * @return mixed
     */
    private function buildClassesTable(){
        return $this->DBManager->setTableName('classes')
            ->addColumn('id', 'int', 11, false, true)
            ->addColumn('class_id', 'int', 11, true, false, true)
            ->addColumn('class_name', 'varchar', 45, true, false, true)
            ->addColumn('class_description_classes', 'varchar', 45, true, false, true)
            ->addColumn('can_be_tank', 'int', 11, true, false, true)
            ->addColumn('can_be_heal', 'int', 11, true, false, true)
            ->addColumn('can_be_dps', 'int', 11, true, false, true)
            ->addColumn('melee_damage', 'int', 11, true, false, true)
            ->addColumn('ranged_physical', 'int', 11, true, false, true)
            ->addColumn('ranged_arcane', 'int', 11, true, false, true)
            ->addColumn('class_description_personal_header', 'varchar', 45, true, false, true)
            ->addColumn('class_description_personal_top', 'varchar', 45, true, false, true)
            ->addColumn('class_description_personal', 'varchar', 45, true, false, true)
            ->addColumn('indicator_first_type', 'varchar', 45, true, false, true)
            ->addColumn('indicator_second_type', 'varchar', 45, true, false, true)
            ->addColumn('first_specialization_image', 'varchar', 45, true, false, true)
            ->addColumn('second_specialization_image', 'varchar', 45, true, false, true)
            ->addColumn('third_specialization_image', 'varchar', 45, true, false, true)
            ->build();
    }

    /**
     * Create Comments Table
     * @return mixed
     */
    private function buildCommentsTable(){
        return $this->DBManager->setTableName('comments')
            ->addColumn('id', 'int', 11, false, true)
            ->addColumn('article_id', 'int', 11, true, false, true)
            ->addColumn('comment_by', 'varchar', 45, true, false, true)
            ->addColumn('comment_text', 'text', false, true, false)
            ->addColumn('comment_date', 'datetime', false, true, false, true)
            ->addColumn('replied_to', 'int', 11, true, false, true)
            ->addColumn('votes_up', 'int', 11, true, false, true)
            ->addColumn('votes_down', 'int', 11, true, false, true)
            ->build();
    }

    private function buildDBVersionTable(){
        return $this->DBManager->setTableName('db_version')
            ->addColumn('id', 'int', 11, false, true)
            ->addColumn('database_version', 'varchar', 128, true, false, true)
            ->addColumn('database_revision', 'int', 11, true, false, true)
            ->addColumn('update_date', 'varchar', 45, true, false, true)
            ->addColumn('install_date', 'varchar', 45, true, false, true)
            ->build();
    }
}