<?php

/**
* Helper class providing utility functions
*/
class Helper
{
    const CHUNK_SIZE = 1048576; // Size (in bytes) of tiles chunk

    // Read a file and display its content chunk by chunk
    static public function readfile_chunked($filename, $retbytes = TRUE) {
        $buffer = '';
        $cnt =0;
        // $handle = fopen($filename, 'rb');
        $handle = fopen($filename, 'rb');
        if ($handle === false) {
            return false;
        }
        while (!feof($handle)) {
            $buffer = fread($handle, self::CHUNK_SIZE);
            echo $buffer;
            ob_flush();
            flush();
            if ($retbytes) {
                $cnt += strlen($buffer);
            }
        }
        $status = fclose($handle);
        if ($retbytes && $status) {
            return $cnt; // return num. bytes delivered like readfile() does.
        }
        return $status;
    }

    static public function nl2br_skip_html($string) {
        // remove any carriage returns (Windows)
        $string = str_replace("\r", '', $string);

        // replace any newlines that aren't preceded by a > with a <br />
        $string = preg_replace('/(?<!>)\n/', "<br />\n", $string);

        return $string;
    }
    
    static public function array_orderby()
    {
        $args = func_get_args();
        $data = array_shift($args);
        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = array();
                foreach ($data as $key => $row)
                    $tmp[$key] = $row[$field];
                $args[$n] = $tmp;
                }
        }
        $args[] = &$data;
        @call_user_func_array('array_multisort', $args);
        return array_pop($args);
    }
    
    // map a device UDID into a username
    static protected function mapUser($user, $userlist)
    {
        $username = $user;
        $lines = explode("\n", $userlist);

        foreach ($lines as $i => $line) :
            if ($line == "") continue;
            
            $userelement = explode(";", $line);

            if (count($userelement) >= 2) {
                if ($userelement[0] == $user) {
                    $username = $userelement[1];
                    break;
                }
            }
        endforeach;

        return $username;
    }
    
    // map a device UDID into a list of assigned teams
    static protected function mapTeam($user, $userlist)
    {
        $teams = "";
        $lines = explode("\n", $userlist);

        foreach ($lines as $i => $line) :
            if ($line == "") continue;
            
            $userelement = explode(";", $line);

            if (count($userelement) == 3) {
                if ($userelement[0] == $user) {
                    $teams = $userelement[2];
                    break;
                }
            }
        endforeach;

        return $teams;
    }
    
    // map a device code into readable name
    static protected function mapPlatform($device)
    {
        $platform = $device;
        
        switch ($device) {
            case "i386":
                $platform = "iPhone Simulator";
                break;
            case "iPhone1,1":
                $platform = "iPhone";
                break;
            case "iPhone1,2":
                $platform = "iPhone 3G";
                break;
            case "iPhone2,1":
                $platform = "iPhone 3GS";
                break;
            case "iPhone3,1":
                $platform = "iPhone 4";
                break;
            case "iPad1,1":
                $platform = "iPad";
                break;
            case "iPod1,1":
                $platform = "iPod Touch";
                break;
            case "iPod2,1":
                $platform = "iPod Touch 2nd Gen";
                break;
            case "iPod3,1":
                $platform = "iPod Touch 3rd Gen";
                break;
            case "iPod4,1":
                $platform = "iPod Touch 4th Gen";
                break;
        }
        
        return $platform;
    }
    
    public function sendFile($filename, $content_type = 'application/octet-stream')
    {
        ob_end_clean();
        header('Content-Disposition: attachment; filename=' . urlencode(basename($filename)));
        header("Content-Type: $content_type");
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: '.filesize($filename)."\n");
        Helper::readfile_chunked($filename);
        exit;
    }
    
    
}

?>