<?php 

interface FTPInterface
{    
    public function connect();
    public function nlist($path);
    public function mkdir($dir);
    public function close();
    public function put($remote, $local);
    public function delete($path);
}

class FTPConnection implements FTPInterface
{
    private $con;
    private $ip;
    private $login;
    private $password;
    
    public function __construct($ip, $login, $password) {
        
        if (!extension_loaded('ftp')) {
            throw new \Exception('The ftp php extension is missing.');
        };
        
        $this->con      = null;
        $this->ip       = $ip;
        $this->login    = $login;
        $this->password = $password;
    }
    
    public function connect() {
        
        $con = ftp_connect($this->ip);
        
        if (false === $con) {
            return false;
        }
        
         $con = ftp_login($con,  $this->login,  $this->password);
         
         if (false == $con) {
             return false;
         }
         
         $this->con = $con;
         
         return true;
    }
    
    public function nlist($path) {
        return ftp_nlist($this->con, $path);
    }
    
    public function mkdir($dir) {
        return ftp_mkdir($this->con, $dir);
    }
    
    public function close() {
        return ftp_close($this->con);
    }
    
    public function put($remote, $local) {
        return ftp_put($this->con, $remote, $local, FTP_BINARY);
    }
    
    public function delete($path) {
        return ftp_delete($this->con, $path);
    }
}

class SFTPConnection implements FTPInterface
{
    private $ip;
    private $login;
    private $pubkey;
    private $privkey;
    private $password;
    private $session;
    private $sftp;
    
    public function __construct($ip, $login, $pubkey, $privkey, $password) {
        
        if (!extension_loaded('ssh2')) {
            throw new \Exception('The ssh2 php extension is missing.');
        };
        
        $this->session  = null;
        $this->sftp     = null;
        $this->ip       = $ip;
        $this->login    = $login;
        $this->pubkey   = $pubkey;
        $this->privkey  = $privkey;
        $this->password = $password;
    }
    
    public function connect() {
        $session = ssh2_connect($this->ip);

        $bool = ssh2_auth_pubkey_file(
            $session, 
            $this->login, 
            $this->pubkey, 
            $this->privkey,
            $this->password
        );
        
        if (false === $bool) {
            return false;
        }
        
        $this->sftp = ssh2_sftp($session);
        $this->session = $session;
        
        return true;
    }
    
    public function nlist($path) {
        $items = scandir("ssh2.sftp://{$this->sftp}/home/{$this->login}/{$path}");
        $el = [];
        
        foreach ($items as $item) {
            if ($item != '.' && $item != '..') {
                $el[] = $item;
            }
        }

        return $el;
    }
    
    public function mkdir($dir) {
        $dir = "/home/{$this->login}/" . $dir;
        
        return ssh2_sftp_mkdir($this->sftp, $dir);
    }
    
    public function close() {
        return ssh2_exec($this->session, 'exit');
    }
    
    public function put($remote, $local) {
        $remote = "/home/{$this->login}/" . $remote;
        
        return ssh2_scp_send($this->session, $local, $remote);
    }
    
    public function delete($file) {
        return ssh2_sftp_unlink($this->sftp, $file);
    }
}

abstract class FTPFactory
{
    private static $types    = array('sftp', 'ftp');
    private static $ftpData  = array('host', 'login', 'password');
    private static $sftpData = array('host', 'login', 'pubkey', 'privkey', 'password');
    
    public static function getConnection($type, array $data)
    {
        if ($type === 'ftp') {
            //test if data is correct
            return new FTPConnection(
                $data['host'], 
                $data['login'], 
                $data['password']
            );
        }
        elseif ($type === 'sftp') {
            //test if data is correct
            
            return new SFTPConnection(
                $data['host'], 
                $data['login'], 
                $data['pubkey'], 
                $data['privkey'], 
                $data['password']
            );
        }
        else {
            $valid = '';
            
            foreach (self::$types as $type) {
                $valid.= "'{$type}', ";
            }
            
            $string = "{$type} is not a valid connection type (valid types: {$valid}";
            throw new \Excepton("{$type} is not a valid connection type");
        }
    }
}
