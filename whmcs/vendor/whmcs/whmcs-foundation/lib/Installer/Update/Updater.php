<?php 
namespace WHMCS\Installer\Update;


class Updater
{
    protected $cachedPackagesDataFile = NULL;

    const FALLBACK_UPDATE_CHANNEL = \WHMCS\Installer\Composer\ComposerJson::STABILITY_STABLE;

    public function getLatestVersion()
    {
        $latestVersion = \WHMCS\Config\Setting::getValue("UpdaterLatestVersion");
        if( empty($latestVersion) ) 
        {
            $latestVersion = \App::getLicense()->getLatestVersion();
        }
        else
        {
            $latestVersion = new \WHMCS\Version\SemanticVersion($latestVersion);
        }

        return $latestVersion;
    }

    public function isUpdateAvailable()
    {
        $latestVersion = $this->getLatestVersion();
        $installedVersion = \App::getVersion();
        return \WHMCS\Version\SemanticVersion::compare($latestVersion, $installedVersion, ">");
    }

    public function getInstalledMajorMinorVersion()
    {
        $installedVersion = \App::getVersion()->getRelease();
        $parts = explode(".", $installedVersion);
        return $parts[0] . "." . $parts[1];
    }

    public function getVersionParts($version)
    {
        return array( "number" => $this->getVersionNumber($version->getCasual()), "label" => $this->getVersionLabel($version->getCasual()), "full" => $version->getCanonical() );
    }

    public function getVersionNumber($version)
    {
        $version = explode(" ", $version, 2);
        return $version[0];
    }

    public function getVersionLabel($version)
    {
        $version = explode(" ", $version, 2);
        return (empty($version[1]) ? "General Release" : $version[1]);
    }

    public function getChannel()
    {
        return \WHMCS\Config\Setting::getValue("WHMCSUpdatePinVersion");
    }

    public function setChannel($channel)
    {
        $validChannels = $this->getUpdateChannels();
        if( !array_key_exists($channel, $validChannels) ) 
        {
            throw new \WHMCS\Exception("Invalid channel");
        }

        if( $channel != $this->getChannel() ) 
        {
            \WHMCS\Config\Setting::setValue("WHMCSUpdatePinVersion", $channel);
            return true;
        }

        return false;
    }

    public function getTemporaryPath()
    {
        return \WHMCS\Config\Setting::getValue("UpdateTempPath");
    }

    public function setTemporaryPath($path)
    {
        if( !is_dir($path) ) 
        {
            throw new \WHMCS\Exception("Invalid path");
        }

        if( !is_writable($path) ) 
        {
            throw new \WHMCS\Exception("Not writable");
        }

        \WHMCS\Config\Setting::setValue("UpdateTempPath", $path);
    }

    public function getMaintenanceMessage()
    {
        return \WHMCS\Config\Setting::getValue("UpdateMaintenanceMessage");
    }

    public static function isAutoUpdateInProgress()
    {
        return (bool) \WHMCS\Config\Setting::getValue("AutoUpdateInProgress");
    }

    public static function isAutoUpdateInProgressByCurrentAdminUser()
    {
        return 0 < \WHMCS\Session::get("adminid") && \WHMCS\Config\Setting::getValue("AutoUpdateAdminId") == \WHMCS\Session::get("adminid");
    }

    public function enableAutoUpdateMaintenanceMsg()
    {
        \WHMCS\Config\Setting::setValue("AutoUpdateInProgress", 1);
        \WHMCS\Config\Setting::setValue("AutoUpdateAdminId", \WHMCS\Session::get("adminid"));
    }

    public function disableAutoUpdateMaintenanceMsg()
    {
        \WHMCS\Config\Setting::setValue("AutoUpdateInProgress", 0);
        \WHMCS\Config\Setting::setValue("AutoUpdateAdminId", 0);
    }

    public function getLastCheckedForUpdates()
    {
        $lastCheck = \WHMCS\Config\Setting::getValue("UpdatesLastChecked");
        if( empty($lastCheck) ) 
        {
            return "Never";
        }

        return \Carbon\Carbon::createFromFormat("Y-m-d H:i:s", $lastCheck)->diffForHumans();
    }

    public function isUpdateTempPathConfigured()
    {
        $updateTempPath = \WHMCS\Config\Setting::getValue("UpdateTempPath");
        return !empty($updateTempPath);
    }

    public function isUpdateTempPathWriteable()
    {
        $updateTempPath = \WHMCS\Config\Setting::getValue("UpdateTempPath");
        return is_writeable($updateTempPath);
    }

    public function getUpdateChannels()
    {
        $channels = array( "stable" => array( "displayLabel" => "Stable", "description" => "Recommended for Production Use", "formatter" => "<span class=\"alert-success\" style=\"padding: 2px 8px;border-radius: 3px;\">%s</span>" ), "RC" => array( "displayLabel" => "Release Candidate", "description" => "" ), "beta" => array( "displayLabel" => "Beta", "description" => "Use for testing and development only" ), $this->getInstalledMajorMinorVersion() => array( "displayLabel" => "Current Version", "description" => "Restricts to maintenance updates for the currently installed version (" . $this->getInstalledMajorMinorVersion() . ")" ) );
        if( !array_key_exists($this->getChannel(), $channels) && $this->getChannel() ) 
        {
            $channels[$this->getChannel()] = array( "displayLabel" => ucfirst($this->getChannel()), "description" => "You are set to a no longer available channel and must select a new one", "formatter" => "<span class=\"textgrey\">%s</span>", "disabled" => true );
        }

        return $channels;
    }

    public function setConfiguration($channel, $temporaryPath, $maintenanceMsg)
    {
        if( !checkPermission("Modify Update Configuration", true) ) 
        {
            return array( "noPermission" => true );
        }

        $response = array(  );
        try
        {
            $response["channelChanged"] = $this->setChannel($channel);
        }
        catch( \WHMCS\Exception $e ) 
        {
            $response["invalidChannel"] = true;
            $response["channelChanged"] = false;
        }
        try
        {
            $this->setTemporaryPath($temporaryPath);
        }
        catch( \WHMCS\Exception $e ) 
        {
            if( $e->getMessage() == "Invalid path" ) 
            {
                $response["invalidPath"] = true;
            }
            else
            {
                $response["pathNotWriteable"] = true;
            }

        }
        \WHMCS\Config\Setting::setValue("UpdateMaintenanceMessage", $maintenanceMsg);
        return $response;
    }

    public function updateRemoteComposerData()
    {
        $results = $this->fetchComposerLatestVersion();
        $this->fetchComposerLatestByTier(\WHMCS\Installer\Composer\ComposerJson::STABILITY_BETA, "UpdaterLatestBetaVersion");
        $this->fetchComposerLatestByTier(\WHMCS\Installer\Composer\ComposerJson::STABILITY_STABLE, "UpdaterLatestStableVersion");
        $this->fetchLTSInfo();
        $results["alerts"] = $this->getUpgradeAlerts();
        return $results;
    }

    private function fetchComposerLatestByTier($tier, $setting)
    {
        $updateTempPath = $this->getTemporaryPath();
        if( empty($updateTempPath) ) 
        {
            $updateTempPath = \App::getAttachmentsDir();
        }

        if( $tier == \WHMCS\Config\Setting::getValue("WHMCSUpdatePinVersion") ) 
        {
            $version = $this->getLatestVersion();
            \WHMCS\Config\Setting::setValue($setting, $version->getCanonical());
        }
        else
        {
            $storage = \DI::make("runtimeStorage");
            $updaterUseCachedPackagesFile = $storage["updaterUseCachedPackagesFile"];
            $storage["updaterUseCachedPackagesFile"] = true;
            $composerUpdater = new \WHMCS\Installer\Composer\ComposerUpdate($updateTempPath);
            $composerUpdater->pinUpdateChannel($tier);
            try
            {
                $version = $composerUpdater->getLatestVersion(true);
            }
            catch( \WHMCS\Exception $e ) 
            {
                $throwMsg = $e->getMessage();
                $failedToRetrieveMsg = "Failed to retrieve latest version";
                if( substr($throwMsg, 0, strlen($failedToRetrieveMsg)) == $failedToRetrieveMsg && strpos($throwMsg, "[Composer\\Downloader\\TransportException]") ) 
                {
                    $throwMsg = "Unable to connect to the WHMCS Update Server. Please try again later or contact support.";
                    throw new \WHMCS\Exception($throwMsg);
                }

                $version = $this->getLatestVersion();
            }
            $storage["updaterUseCachedPackagesFile"] = $updaterUseCachedPackagesFile;
            \WHMCS\Config\Setting::setValue($setting, $version->getCanonical());
        }

    }

    private function fetchComposerLatestWithoutSupportAndUpdates()
    {
        $license = \DI::make("license");
        if( !$license->getRequiresUpdates() ) 
        {
            $version = $this->getLatestVersion();
            \WHMCS\Config\Setting::setValue("UpdaterLatestSupportAndUpdatesVersion", $version->getCanonical());
        }
        else
        {
            $updateTempPath = $this->getTemporaryPath();
            if( empty($updateTempPath) ) 
            {
                $updateTempPath = \App::getAttachmentsDir();
            }

            $composerUpdater = new \WHMCS\Installer\Composer\ComposerUpdate($updateTempPath);
            $composerUpdater->setSkipLicenseCheck(true);
            $composerUpdater->pinUpdateChannel($this->getChannel());
            try
            {
                $version = $composerUpdater->getLatestVersion(true);
            }
            catch( \WHMCS\Exception $e ) 
            {
                $throwMsg = $e->getMessage();
                $failedToRetrieveMsg = "Failed to retrieve latest version";
                if( substr($throwMsg, 0, strlen($failedToRetrieveMsg)) == $failedToRetrieveMsg && strpos($throwMsg, "[Composer\\Downloader\\TransportException]") ) 
                {
                    $throwMsg = "Unable to connect to the WHMCS Update Server. Please try again later or contact support.";
                    throw new \WHMCS\Exception($throwMsg);
                }

                $version = $this->getLatestVersion();
            }
            \WHMCS\Config\Setting::setValue("UpdaterLatestSupportAndUpdatesVersion", $version->getCanonical());
        }

    }

    public function fetchLTSInfo()
    {
        $channels = new \WHMCS\Installer\Composer\Channels();
        $channels->downloadRemoteLtsJson();
        $ltsJson = json_decode($channels->getLtsJson(), true);
        \WHMCS\Config\Setting::setValue("UpdaterLTS", json_encode($ltsJson));
    }

    public function getUpgradeAlerts()
    {
        $channel = new \WHMCS\Installer\Composer\Channels();
        $channel->setLtsJson(\WHMCS\Config\Setting::getValue("UpdaterLTS"));
        $notificationRepo = new \WHMCS\Installer\Composer\UpdateNotificationRepository($channel);
        $notificationRepo->setUpdateVersion(\WHMCS\Config\Setting::getValue("UpdaterLatestVersion"));
        $notificationRepo->setLatestStableVersion(\WHMCS\Config\Setting::getValue("UpdaterLatestStableVersion"));
        $notificationRepo->setLatestBetaVersion(\WHMCS\Config\Setting::getValue("UpdaterLatestBetaVersion"));
        $notificationRepo->setLatestSupportAndUpdatesVersion(\WHMCS\Config\Setting::getValue("UpdaterLatestSupportAndUpdatesVersion"));
        $notifications = $notificationRepo->getNotifications();
        $license = \DI::make("license");
        return array( "rcAvailable" => !is_null($notifications["rcAvailable"]), "betaAvailable" => !is_null($notifications["betaAvailable"]), "pinnedBlock" => !is_null($notifications["pinnedBlock"]), "pinnedEol" => !is_null($notifications["pinnedEol"]), "updatesBlock" => !is_null($notifications["updatesBlock"]), "updatesExpired" => $license->getRequiresUpdates() && \Carbon\Carbon::createFromFormat("Y-m-d", $license->getUpdatesExpirationDate()) < \Carbon\Carbon::today() );
    }

    public function fetchComposerLatestVersion()
    {
        $updateTempPath = $this->getTemporaryPath();
        $pinnedVersion = $this->getChannel();
        if( empty($updateTempPath) ) 
        {
            $tempPathConfigured = false;
            $updateTempPath = \App::getAttachmentsDir();
        }
        else
        {
            $tempPathConfigured = true;
        }

        $composerUpdater = new \WHMCS\Installer\Composer\ComposerUpdate($updateTempPath);
        try
        {
            try
            {
                $composerUpdater->pinUpdateChannel($pinnedVersion);
            }
            catch( \WHMCS\Exception $e ) 
            {
                $composerUpdater->pinUpdateChannel(static::FALLBACK_UPDATE_CHANNEL);
            }
        }
        catch( \WHMCS\Exception $e ) 
        {
            $throwMsg = $e->getMessage();
            $unsupportedTierMsg = "Unsupported core stability tier:";
            if( substr($throwMsg, 0, strlen($unsupportedTierMsg)) == $unsupportedTierMsg ) 
            {
                $throwMsg = "Please ensure you have selected a valid Update Channel and then try again.";
            }

            throw new \WHMCS\Exception($throwMsg);
        }
        $throwMsg = null;
        $latestVersion = null;
        try
        {
            $latestVersion = $composerUpdater->getLatestVersion(true);
            $this->fetchComposerLatestWithoutSupportAndUpdates();
        }
        catch( \WHMCS\Exception $e ) 
        {
            $throwMsg = $e->getMessage();
            $failedToRetrieveMsg = "Failed to retrieve latest version";
            if( substr($throwMsg, 0, strlen($failedToRetrieveMsg)) == $failedToRetrieveMsg && strpos($throwMsg, "[Composer\\Downloader\\TransportException]") ) 
            {
                $throwMsg = "Unable to connect to the WHMCS Update Server. Please try again later or contact support.";
            }

        }
        if( !$tempPathConfigured ) 
        {
            try
            {
                \WHMCS\Utility\File::recursiveDelete($updateTempPath . DIRECTORY_SEPARATOR . "composer", array(  ), true);
            }
            catch( \WHMCS\Exception $e ) 
            {
            }
        }

        if( $throwMsg ) 
        {
            throw new \WHMCS\Exception($throwMsg);
        }

        if( $latestVersion ) 
        {
            \WHMCS\Config\Setting::setValue("UpdaterLatestVersion", $latestVersion->getCanonical());
            \WHMCS\Config\Setting::setValue("UpdatesLastChecked", \Carbon\Carbon::now());
        }
        else
        {
            try
            {
                $latestVersion = new \WHMCS\Version\SemanticVersion(\WHMCS\Config\Setting::getValue("UpdaterLatestVersion"));
            }
            catch( \Exception $e ) 
            {
                $latestVersion = \App::getVersion();
            }
        }

        return array( "latestVersion" => $this->getVersionParts($latestVersion), "canUpdate" => $composerUpdater->canUpdate($latestVersion), "releaseNotesUrl" => $this->getReleaseNotesUrl($latestVersion), "changelogUrl" => $this->getChangelogUrl($latestVersion) );
    }

    public function performFileUpdate()
    {
        $updateTempPath = $this->getTemporaryPath();
        $pinnedVersion = $this->getChannel();
        $updateCount = (int) \WHMCS\Config\Setting::getValue("AutoUpdateCount");
        $updateCount += 1;
        \WHMCS\Config\Setting::setValue("AutoUpdateCount", $updateCount);
        $this->enableAutoUpdateMaintenanceMsg();
        $composerUpdater = new \WHMCS\Installer\Composer\ComposerUpdate($updateTempPath);
        $composerUpdater->pinUpdateChannel($pinnedVersion);
        $updateSuccessful = $composerUpdater->update();
        $packageMetadata = ($updateSuccessful ? $composerUpdater->getReleaseMetaData() : array(  ));
        $releaseMetadata = new ReleaseMetadata();
        if( !empty($packageMetadata) ) 
        {
            $releaseMetadata->setFromPackageMetadata(\WHMCS\Config\Setting::getValue("UpdaterLatestVersion"), $packageMetadata)->save();
        }

        $errorMessage = "";
        if( $updateSuccessful ) 
        {
            try
            {
                $smarty = new \WHMCS\Smarty();
                $smarty->clearAllCaches();
            }
            catch( \Exception $e ) 
            {
                logActivity("Error cleaning template cache during update: " . $e->getMessage());
            }
        }
        else
        {
            $transformer = new \WHMCS\View\Markup\Error\ComposerOutput();
            $errorMessage = $transformer->transform($composerUpdater->getUpdateLog());
        }

        return array( "updateSuccessful" => $updateSuccessful, "releaseNoteUrl" => $releaseMetadata->getReleaseNotesUrl(), "changeLogUrl" => $releaseMetadata->getChangeLogUrl(), "debugOutput" => strip_tags($composerUpdater->getUpdateLog()), "errorMessage" => $errorMessage );
    }

    protected function getCachedPackagesDataFile()
    {
        if( is_null($this->cachedPackagesDataFile) ) 
        {
            $cache = new \WHMCS\TransientData();
            $this->cachedPackagesDataFile = json_decode($cache->retrieve("UpdatePackagesDataFile"), true);
        }

        return $this->cachedPackagesDataFile;
    }

    public function getReleaseNotesUrl($version = NULL)
    {
        $cachedData = $this->getCachedPackagesDataFile();
        if( is_null($version) ) 
        {
            $version = $this->getLatestVersion();
        }

        return (isset($cachedData["packages"]["whmcs/whmcs"][$version->getCanonical()]["extra"]["releaseNotesUrl"]) ? $cachedData["packages"]["whmcs/whmcs"][$version->getCanonical()]["extra"]["releaseNotesUrl"] : "http://docs.whmcs.com/Release_Notes");
    }

    public function getChangelogUrl($version = NULL)
    {
        $cachedData = $this->getCachedPackagesDataFile();
        if( is_null($version) ) 
        {
            $version = $this->getLatestVersion();
        }

        return (isset($cachedData["packages"]["whmcs/whmcs"][$version->getCanonical()]["extra"]["changeLogUrl"]) ? $cachedData["packages"]["whmcs/whmcs"][$version->getCanonical()]["extra"]["changeLogUrl"] : "http://changelog.whmcs.com/");
    }

    public function getMemoryLimitRequiredToUpdateTo(\WHMCS\Version\SemanticVersion $version = NULL)
    {
        if( !is_null($version) ) 
        {
            $cachedData = $this->getCachedPackagesDataFile();
            if( $cachedData ) 
            {
                $canonicalVersion = $version->getCanonical();
                if( isset($cachedData["packages"]["whmcs/whmcs"][$canonicalVersion]) ) 
                {
                    $versionData = $cachedData["packages"]["whmcs/whmcs"][$canonicalVersion];
                    if( isset($versionData["extra"]["minMemoryLimit"]) ) 
                    {
                        $minMemoryLimit = $versionData["extra"]["minMemoryLimit"];
                        if( is_numeric($minMemoryLimit) && \WHMCS\View\Admin\HealthCheck\HealthCheckRepository::DEFAULT_MEMORY_LIMIT_FOR_AUTO_UPDATE <= $minMemoryLimit ) 
                        {
                            return $minMemoryLimit;
                        }

                    }

                }

            }

        }

        return \WHMCS\View\Admin\HealthCheck\HealthCheckRepository::DEFAULT_MEMORY_LIMIT_FOR_AUTO_UPDATE;
    }

}


