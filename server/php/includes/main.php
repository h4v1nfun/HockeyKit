<?php

## index.php
## 
##  Created by Andreas Linde on 8/17/10.
##             Stanley Rost on 8/17/10.
##  Copyright 2010 Andreas Linde. All rights reserved.
##
##  Permission is hereby granted, free of charge, to any person obtaining a copy
##  of this software and associated documentation files (the "Software"), to deal
##  in the Software without restriction, including without limitation the rights
##  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
##  copies of the Software, and to permit persons to whom the Software is
##  furnished to do so, subject to the following conditions:
##
##  The above copyright notice and this permission notice shall be included in
##  all copies or substantial portions of the Software.
##
##  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
##  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
##  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
##  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
##  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
##  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
##  THE SOFTWARE.

date_default_timezone_set('UTC');

require('json.inc');
require('plist.inc');
require_once('config.inc');
require_once('helper.php');
require_once('logger.php');

class AppUpdater
{
    // define the parameters being sent by the client checking for a new version
    const CLIENT_KEY_TYPE       = 'type';
    const CLIENT_KEY_BUNDLEID   = 'bundleidentifier';
    const CLIENT_KEY_APIVERSION = 'api';
    const CLIENT_KEY_UDID       = 'udid';                   // iOS client only
    const CLIENT_KEY_APPVERSION = 'version';
    const CLIENT_KEY_IOSVERSION = 'ios';                    // iOS client only
    const CLIENT_KEY_PLATFORM   = 'platform';
    const CLIENT_KEY_LANGUAGE   = 'lang';
    
    // define URL type parameter values
    const TYPE_PROFILE  = 'profile';
    const TYPE_APP      = 'app';
    const TYPE_IPA      = 'ipa';
    const TYPE_APK      = 'apk';
    const TYPE_AUTH     = 'authorize';

    // define the json response format version
    const API_V1 = '1';
    const API_V2 = '2';
    
    // define support app platforms
    const APP_PLATFORM_IOS      = "iOS";
    const APP_PLATFORM_ANDROID  = "Android";
    
    // define keys for the returning json string api version 1
    const RETURN_RESULT   = 'result';
    const RETURN_NOTES    = 'notes';
    const RETURN_TITLE    = 'title';
    const RETURN_SUBTITLE = 'subtitle';

    // define keys for the returning json string api version 2
    const RETURN_V2_VERSION         = 'version';
    const RETURN_V2_SHORTVERSION    = 'shortversion';
    const RETURN_V2_NOTES           = 'notes';
    const RETURN_V2_TITLE           = 'title';
    const RETURN_V2_TIMESTAMP       = 'timestamp';
    const RETURN_V2_APPSIZE         = 'appsize';
    const RETURN_V2_AUTHCODE        = 'authcode';

    const RETURN_V2_AUTH_FAILED     = 'FAILED';

    // define keys for the array to keep a list of available beta apps to be displayed in the web interface
    const INDEX_APP             = 'app';
    const INDEX_VERSION         = 'version';
    const INDEX_SUBTITLE        = 'subtitle';
    const INDEX_DATE            = 'date';
    const INDEX_APPSIZE         = 'appsize';
    const INDEX_NOTES           = 'notes';
    const INDEX_PROFILE         = 'profile';
    const INDEX_PROFILE_UPDATE  = 'profileupdate';
    const INDEX_DIR             = 'dir';
    const INDEX_IMAGE           = 'image';
    const INDEX_STATS           = 'stats';
    const INDEX_PLATFORM        = 'platform';

    // define filetypes
    const FILE_IOS_PLIST        = '.plist';
    const FILE_IOS_IPA          = '.ipa';
    const FILE_IOS_PROFILE      = '.mobileprovision';
    const FILE_ANDROID_JSON     = '.json';
    const FILE_ANDROID_APK      = '.apk';
    const FILE_COMMON_NOTES     = '.html';
    const FILE_COMMON_ICON      = '.png';
    
    const FILE_VERSION_RESTRICT = '.team';                  // if present in a version subdirectory, defines the teams that do have access, comma separated
    const FILE_USERLIST         = 'stats/userlist.txt';     // defines UDIDs, real names for stats, and comma separated the associated team names
    
    // define version array structure
    const VERSIONS_COMMON_DATA      = 'common';
    const VERSIONS_SPECIFIC_DATA    = 'specific';
    
    // define keys for the array to keep a list of devices installed this app
    const DEVICE_USER       = 'user';
    const DEVICE_PLATFORM   = 'platform';
    const DEVICE_OSVERSION  = 'osversion';
    const DEVICE_APPVERSION = 'appversion';
    const DEVICE_LANGUAGE   = 'language';
    const DEVICE_LASTCHECK  = 'lastcheck';

    const CONTENT_TYPE_APK = 'application/vnd.android.package-archive';


    static public function factory($dir, $platform = '') {
        
        // route platform calls
        if (
            // iOS network requests, which means the client is calling, old versions don't add a custom user agent
            strpos($_SERVER['HTTP_USER_AGENT'], 'CFNetwork') !== false ||
            // iOS hockey client is calling
            strpos($_SERVER['HTTP_USER_AGENT'], 'Hockey/iOS') !== false
        )
        {
            $platform = 'iOS';
        }
        elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'Hockey/Android') !== false) // Android hockey client is calling
        {
            $platform = 'Android';
        }
        
        if ($platform) {
            if (!file_exists(strtolower("platforms/$platform.php"))) {
                throw new Exception("Platform $platform does not exist.");
            }
        
            require_once(strtolower("platforms/abstract.php"));
            require_once(strtolower("platforms/$platform.php"));
        }
        
        $klass = "{$platform}AppUpdater";
        Logger::log("Factory: Creating $klass");
        return new $klass($dir);
    }


    protected $appDirectory;
    protected $json = array();
    public $applications = array();

    
    protected function __construct($dir) {
        $this->appDirectory = $dir;
    }
    
    public function route() {
        $bundleidentifier = isset($_GET[self::CLIENT_KEY_BUNDLEID]) ?
            $this->validateDir($_GET[self::CLIENT_KEY_BUNDLEID]) : null;
        $type = isset($_GET[self::CLIENT_KEY_TYPE]) ?
            $this->validateType($_GET[self::CLIENT_KEY_TYPE]) : null;
        $api = isset($_GET[self::CLIENT_KEY_APIVERSION]) ?
            $this->validateAPIVersion($_GET[self::CLIENT_KEY_APIVERSION]) : self::API_V1;
        
        // if a bundleidentifier and type are requested, deliver that type.
        if ($bundleidentifier && $type) {
            return $this->deliver($bundleidentifier, $api, $type);
        }
        
        // if a bundleidentifier is provided, only show that app
        $this->show($bundleidentifier);
    }
    
    protected function validateDir($dir)
    {
       // do not allow .. or / in the name and check if that path actually exists
       if (
           $dir &&
           !preg_match('#(/|\.\.)#u', $dir) &&
           file_exists($this->appDirectory.$dir))
       {
           return $dir;
       }
       return null;
    }

    protected function validateType($type)
    {
        if (in_array($type, array(self::TYPE_PROFILE, self::TYPE_APP, self::TYPE_IPA, self::TYPE_AUTH, self::TYPE_APK)))
        {
            return $type;
        }
        return null;
    }

    protected function validateAPIVersion($api)
    {
        if (in_array($api, array(self::API_V1, self::API_V2)))
        {
            return $api;
        }
        return self::API_V1;
    }
  
    
    protected function addStats($bundleidentifier)
    {
        // did we get any user data?
        $udid = isset($_GET[self::CLIENT_KEY_UDID]) ? $_GET[self::CLIENT_KEY_UDID] : null;
        $appversion = isset($_GET[self::CLIENT_KEY_APPVERSION]) ? $_GET[self::CLIENT_KEY_APPVERSION] : "";
        $osversion = isset($_GET[self::CLIENT_KEY_IOSVERSION]) ? $_GET[self::CLIENT_KEY_IOSVERSION] : "";
        $platform = isset($_GET[self::CLIENT_KEY_PLATFORM]) ? $_GET[self::CLIENT_KEY_PLATFORM] : "";
        $language = isset($_GET[self::CLIENT_KEY_LANGUAGE]) ? strtolower($_GET[self::CLIENT_KEY_LANGUAGE]) : "";
        
        if ($udid && $type != self::TYPE_AUTH) {
            $thisdevice = $udid.";;".$platform.";;".$osversion.";;".$appversion.";;".date("m/d/Y H:i:s").";;".$language;
            $content =  "";

            $filename = $this->appDirectory."stats/".$bundleidentifier;

            if (is_dir($this->appDirectory."stats/")) {
                $content = @file_get_contents($filename);
            
                $lines = explode("\n", $content);
                $content = "";
                $found = false;
                foreach ($lines as $i => $line) :
                    if ($line == "") continue;
                    $device = explode( ";;", $line);

                    $newline = $line;
                
                    if (count($device) > 0) {
                        // is this the same device?
                        if ($device[0] == $udid) {
                            $newline = $thisdevice;
                            $found = true;
                        }
                    }
                
                    $content .= $newline."\n";
                endforeach;
            
                if (!$found) {
                    $content .= $thisdevice;
                }
            
                // write back the updated stats
                @file_put_contents($filename, $content);
            }
        }
    }
    
    protected function checkProtectedVersion($restrict)
    {
        $allowed = false;
        
        $allowedTeams = @file_get_contents($restrict);
        if (strlen($allowedTeams) == 0) return true;
        $allowedTeams = explode(",", $allowedTeams);
        
        $udid = isset($_GET[self::CLIENT_KEY_UDID]) ? $_GET[self::CLIENT_KEY_UDID] : null;
        if ($udid) {
            // now get the current user statistics
            $userlist =  "";

            $userlistfilename = $this->appDirectory.self::FILE_USERLIST;
            $userlist = @file_get_contents($userlistfilename);
            $assignedTeams = Helper::mapTeam($udid, $userlist);
            if (strlen($assignedTeams) > 0) {
                $teams = explode(",", $assignedTeams);
                foreach ($teams as $team) {
                    if (in_array($team, $allowedTeams)) {
                        $allowed = true;
                        break;
                    }
                }
            }
        }
        
        return $allowed;
    }
    
    protected function getApplicationVersions($bundleidentifier)
    {
        $files = array();
        
        $language = isset($_GET[self::CLIENT_KEY_LANGUAGE]) ? strtolower($_GET[self::CLIENT_KEY_LANGUAGE]) : "";
        
        // iOS
        $ipa        = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*' . self::FILE_IOS_IPA));
        $plist      = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*' . self::FILE_IOS_PLIST));
        $profile    = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*' . self::FILE_IOS_PROFILE));

        // Android
        $apk        = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*' . self::FILE_ANDROID_APK));
        $json       = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*' . self::FILE_ANDROID_JSON));
        
        $note = '';
        // Common
        if ($language != "") {
            $note   = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*' . self::FILE_COMMON_NOTES . '.' . $language));
        }
        if (!$note) {
            $note   = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*' . self::FILE_COMMON_NOTES));   // the default language file should not have a language extension, so if en is default, never creaete a .html.en file!
        }
        $icon       = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*' . self::FILE_COMMON_ICON));
        
        $allVersions = array();
        
        if ((!$ipa || !$plist) && 
            (!$apk || !$json)) {
            // check if any are available in a subdirectory
            
            $subDirs = array();
            if ($handleSub = opendir($this->appDirectory . $bundleidentifier)) {
                while (($fileSub = readdir($handleSub)) !== false) {
                    if (!in_array($fileSub, array('.', '..')) && 
                        is_dir($this->appDirectory . $bundleidentifier . '/'. $fileSub)) {
                        array_push($subDirs, $fileSub);
                    }
                }
                closedir($handleSub);
            }

            // Sort the files and display
            rsort($subDirs);
            
            if (count($subDirs) > 0) {
                foreach ($subDirs as $subDir) {
                    // iOS
                    $ipa        = @array_shift(glob($this->appDirectory.$bundleidentifier . '/'. $subDir . '/*' . self::FILE_IOS_IPA));             // this file could be in a subdirectory per version
                    $plist      = @array_shift(glob($this->appDirectory.$bundleidentifier . '/'. $subDir . '/*' . self::FILE_IOS_PLIST));           // this file could be in a subdirectory per version
                    
                    // Android
                    $apk        = @array_shift(glob($this->appDirectory.$bundleidentifier . '/'. $subDir . '/*' . self::FILE_ANDROID_APK));         // this file could be in a subdirectory per version
                    $json       = @array_shift(glob($this->appDirectory.$bundleidentifier . '/'. $subDir . '/*' . self::FILE_ANDROID_JSON));        // this file could be in a subdirectory per version
                    
                    // Common
                    $note = '';                                                                                                                   // this file could be in a subdirectory per version
                    if ($language != "") {
                        $note   = @array_shift(glob($this->appDirectory.$bundleidentifier . '/'. $subDir . '/*' . self::FILE_COMMON_NOTES . '.' . $language));
                    }
                    if (!$note) {
                        $note   = @array_shift(glob($this->appDirectory.$bundleidentifier . '/'. $subDir . '/*' . self::FILE_COMMON_NOTES));
                    }
                    $restrict   = @array_shift(glob($this->appDirectory.$bundleidentifier . '/'. $subDir . '/*' . self::FILE_VERSION_RESTRICT));    // this file defines the teams allowed to access this version
                                        
                    if ($ipa && $plist) {
                        $version = array();
                        $version[self::FILE_IOS_IPA] = $ipa;
                        $version[self::FILE_IOS_PLIST] = $plist;
                        $version[self::FILE_COMMON_NOTES] = $note;
                        $version[self::FILE_VERSION_RESTRICT] = $restrict;
                        
                        // if this is a restricted version, check if the UDID is provided and allowed
                        if ($restrict && !$this->checkProtectedVersion($restrict)) {
                            continue;
                        }
                        
                        $allVersions[$subDir] = $version;
                    } else if ($apk && $json) {
                        $version = array();
                        $version[self::FILE_ANDROID_APK] = $apk;
                        $version[self::FILE_ANDROID_JSON] = $json;
                        $version[self::FILE_COMMON_NOTES] = $note;
                        $allVersions[$subDir] = $version;
                    }
                }
                if (count($allVersions) > 0) {
                    $files[self::VERSIONS_SPECIFIC_DATA] = $allVersions;
                    $files[self::VERSIONS_COMMON_DATA][self::FILE_IOS_PROFILE] = $profile;
                    $files[self::VERSIONS_COMMON_DATA][self::FILE_COMMON_ICON] = $icon;
                }
            }
        } else {
            $version = array();
            if ($ipa && $plist) {
                $version[self::FILE_IOS_IPA] = $ipa;
                $version[self::FILE_IOS_PLIST] = $plist;
                $version[self::FILE_COMMON_NOTES] = $note;
                $allVersions[] = $version;
                $files[self::VERSIONS_SPECIFIC_DATA] = $allVersions;
                $files[self::VERSIONS_COMMON_DATA][self::FILE_COMMON_ICON] = $icon;
            } else if ($apk && $json) {
                $version[self::FILE_ANDROID_APK] = $apk;
                $version[self::FILE_ANDROID_JSON] = $json;
                $version[self::FILE_COMMON_NOTES] = $note;
                $allVersions[] = $version;
                $files[self::VERSIONS_SPECIFIC_DATA] = $allVersions;
                $files[self::VERSIONS_COMMON_DATA][self::FILE_COMMON_ICON] = $icon;
            }
        }
        return $files;
    }
    
    protected function deliver($bundleidentifier, $api, $type)
    {
        $files = $this->getApplicationVersions($bundleidentifier);

        if (count($files) == 0) {
            $this->json = array(self::RETURN_RESULT => -1);
            return $this->sendJSONAndExit();
        }
                        
        $current = current($files[self::VERSIONS_SPECIFIC_DATA]);
        $ipa   = isset($current[self::FILE_IOS_IPA]) ? $current[self::FILE_IOS_IPA] : null;
        $plist = isset($current[self::FILE_IOS_PLIST]) ? $current[self::FILE_IOS_PLIST] : null;
        $apk   = isset($current[self::FILE_ANDROID_APK]) ? $current[self::FILE_ANDROID_APK] : null;
        $json  = isset($current[self::FILE_ANDROID_JSON]) ? $current[self::FILE_ANDROID_JSON] : null;
        $note  = isset($current[self::FILE_COMMON_NOTES]) ? $current[self::FILE_COMMON_NOTES] : null;

        $profile = isset($files[self::VERSIONS_COMMON_DATA][self::FILE_IOS_PROFILE]) ?
            $files[self::VERSIONS_COMMON_DATA][self::FILE_IOS_PROFILE] : null;
        $image = isset($files[self::VERSIONS_COMMON_DATA][self::FILE_COMMON_ICON]) ?
            $files[self::VERSIONS_COMMON_DATA][self::FILE_COMMON_ICON] : null;
        
        // notes file is optional, other files are required
        if ((!$ipa || !$plist) && 
            (!$apk || !$json)) {
            $this->json = array(self::RETURN_RESULT => -1);
            return $this->sendJSONAndExit();
        }
        
        $this->addStats($bundleidentifier);
        
        switch ($type) {
            case self::TYPE_PROFILE: Helper::sendFile($appDirectory . $profile); break;
            case self::TYPE_APP:     $this->deliverIOSAppPlist($bundleidentifier, $ipa, $plist, $image); break;
            case self::TYPE_IPA:     Helper::sendFile($appDirectory . $ipa); break;
            case self::TYPE_APK:     Helper::sendFile($appDirectory . $apk, self::CONTENT_TYPE_APK); break;
            default: break;
        }

        exit();
    }
    
    protected function sendJSONAndExit()
    {
        ob_end_clean();
        header('Content-type: application/json');
        print json_encode($this->json);
        exit();
    }
    
    protected function findPublicVersion($files)
    {
        $publicVersion = array();
        
        foreach ($files as $version => $fileSet) {
            if (isset($fileSet[self::FILE_ANDROID_APK])) {
                $publicVersion = $fileSet;
                break;
            }
            
            $restrict = isset($fileSet[self::FILE_VERSION_RESTRICT]) ? $fileSet[self::FILE_VERSION_RESTRICT] : null;
            if (isset($fileSet[self::FILE_IOS_IPA]) && $restrict && filesize($restrict) > 0) {
                continue;
            }
            
            $publicVersion = $fileSet;
            break;
        }
        
        return $publicVersion;
    }
    
    protected function show($appBundleIdentifier)
    {
        // first get all the subdirectories, which do not have a file named "private" present
        if ($handle = opendir($this->appDirectory)) {
            while (($file = readdir($handle)) !== false) {
                if (in_array($file, array('.', '..')) || !is_dir($this->appDirectory . $file) || (glob($this->appDirectory . $file . '/private') && !$appBundleIdentifier)) {
                    // skip if not a directory or has `private` file
                    // but only if no bundle identifier is provided to this function
                    continue;
                }
                
                // if a bundle identifier is provided and the directory does not match, continue
                if ($appBundleIdentifier && $file != $appBundleIdentifier) {
                    continue;
                }

                // now check if this directory has the 3 mandatory files
                
                $files = $this->getApplicationVersions($file);
                
                if (count($files) == 0) {
                    continue;
                }
                
                $current = $this->findPublicVersion($files[self::VERSIONS_SPECIFIC_DATA]);
//                $current = current($files[self::VERSIONS_SPECIFIC_DATA]);
                $ipa      = isset($current[self::FILE_IOS_IPA]) ? $current[self::FILE_IOS_IPA] : null;
                $plist    = isset($current[self::FILE_IOS_PLIST]) ? $current[self::FILE_IOS_PLIST] : null;
                $apk      = isset($current[self::FILE_ANDROID_APK]) ? $current[self::FILE_ANDROID_APK] : null;
                $json     = isset($current[self::FILE_ANDROID_JSON]) ? $current[self::FILE_ANDROID_JSON] : null;
                $note     = isset($current[self::FILE_COMMON_NOTES]) ? $current[self::FILE_COMMON_NOTES] : null;
                $restrict = isset($current[self::FILE_VERSION_RESTRICT]) ? $current[self::FILE_VERSION_RESTRICT] : null;
                
                $profile = isset($files[self::VERSIONS_COMMON_DATA][self::FILE_IOS_PROFILE]) ?
                    $files[self::VERSIONS_COMMON_DATA][self::FILE_IOS_PROFILE] : null;
                $image = isset($files[self::VERSIONS_COMMON_DATA][self::FILE_COMMON_ICON]) ?
                    $files[self::VERSIONS_COMMON_DATA][self::FILE_COMMON_ICON] : null;

                if (!$ipa && !$apk) {
                    continue;
                }

                // if this app version has any restrictions, don't show it on the web interface!
                // we make it easy for now and do not check if the data makes sense and has users assigned to the defined team names
                if ($restrict && strlen(file_get_contents($restrict)) > 0) {
                    $current = $this->findPublicVersion($files);
                }
                
                $newApp = array();

                $newApp[self::INDEX_DIR]            = $file;
                $newApp[self::INDEX_IMAGE]          = substr($image, strpos($image, $file));
                $newApp[self::INDEX_NOTES]          = $note ? Helper::nl2br_skip_html(file_get_contents($note)) : '';
                $newApp[self::INDEX_STATS]          = array();

                if ($ipa) {
                    // iOS application
                    $plistDocument = new DOMDocument();
                    $plistDocument->load($plist);
                    $parsed_plist = parsePlist($plistDocument);

                    // now get the application name from the plist
                    $newApp[self::INDEX_APP]            = $parsed_plist['items'][0]['metadata']['title'];
                    if (isset($parsed_plist['items'][0]['metadata']['subtitle']) && $parsed_plist['items'][0]['metadata']['subtitle'])
                        $newApp[self::INDEX_SUBTITLE]   = $parsed_plist['items'][0]['metadata']['subtitle'];
                    $newApp[self::INDEX_VERSION]        = $parsed_plist['items'][0]['metadata']['bundle-version'];
                    $newApp[self::INDEX_DATE]           = filectime($ipa);
                    $newApp[self::INDEX_APPSIZE]        = filesize($ipa);
                    
                    $provisioningProfile = null; // FIXME: $provisioningProfile was never initialized before?
                    if ($provisioningProfile) {
                        $newApp[self::INDEX_PROFILE]        = $provisioningProfile;
                        $newApp[self::INDEX_PROFILE_UPDATE] = filectime($provisioningProfile);
                    }
                    $newApp[self::INDEX_PLATFORM]       = self::APP_PLATFORM_IOS;
                    
                } else if ($apk) {
                    // Android Application
                    
                    // parse the json file
                    $parsed_json = json_decode(file_get_contents($json), true);

                    // now get the application name from the json file
                    $newApp[self::INDEX_APP]        = $parsed_json['title'];
                    $newApp[self::INDEX_SUBTITLE]   = $parsed_json['versionName'];
                    $newApp[self::INDEX_VERSION]    = $parsed_json['versionCode'];
                    $newApp[self::INDEX_DATE]       = filectime($apk);
                    $newApp[self::INDEX_APPSIZE]    = filesize($apk);
                    
                    $newApp[self::INDEX_PLATFORM]   = self::APP_PLATFORM_ANDROID;
                }
                
                // now get the current user statistics
                $userlist =  "";

                $filename = $this->appDirectory."stats/".$file;
                $userlistfilename = $this->appDirectory.self::FILE_USERLIST;
        
                if (file_exists($filename)) {
                    $userlist = @file_get_contents($userlistfilename);
                
                    $content = file_get_contents($filename);
                    $lines = explode("\n", $content);

                    foreach ($lines as $i => $line) {
                        if ($line == "") continue;
                        
                        $device = explode(";;", $line);
                    
                        $newdevice = array();

                        $newdevice[self::DEVICE_USER] = Helper::mapUser($device[0], $userlist);
                        $newdevice[self::DEVICE_PLATFORM] = Helper::mapPlatform($device[1]);
                        $newdevice[self::DEVICE_OSVERSION] = $device[2];
                        $newdevice[self::DEVICE_APPVERSION] = $device[3];
                        $newdevice[self::DEVICE_LASTCHECK] = $device[4];
                        $newdevice[self::DEVICE_LANGUAGE] = $device[5];
                    
                        $newApp[self::INDEX_STATS][] = $newdevice;
                    }
                
                    // sort by app version
                    $newApp[self::INDEX_STATS] = Helper::array_orderby($newApp[self::INDEX_STATS], self::DEVICE_APPVERSION, SORT_DESC, self::DEVICE_OSVERSION, SORT_DESC, self::DEVICE_PLATFORM, SORT_ASC, self::DEVICE_LASTCHECK, SORT_DESC);
                }
            
                // add it to the array
                $this->applications[] = $newApp;
            }
            closedir($handle);
        }
    }
}


?>