<?php
// Fast unpacking MODX - скрипт для быстрой распаковки MODX на ваш хостинг
// Автор исходника - http://dmi3yy.com/
// Допилы - https://tanzirev.ru/

//Включаем вывод ошибок
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);
ini_set('max_execution_time',0);
header('Content-Type: text/html; charset=utf-8');

if(extension_loaded('xdebug')){
    ini_set('xdebug.max_nesting_level', 100000);
}

//Сортировка версий
class VersionSort
{

    /**
     * @var array
     */
    private $versions;

    /**
     *
     * @param array $versions e.g. ['v1.0.1', 'v2.0.0']
     */
    public function __construct(array $versions)
    {
        $this->versions = $versions;
    }

    /**
     * get versions
     * @return array
     */
    public function get()
    {
        return $this->versions;
    }

    /**
     * trim prefix text.
     * e.g
     *   v1.1.0          => 1.1.0
     *   version2.3.RC.1 => 2.3.RC.1
     * @return $this
     */
    public function trim()
    {
        foreach ($this->versions as &$version) {
            $version = preg_replace('/^[^0-9]+/', '', $version->name);
        }
        return $this;
    }

    /**
     * sort asc
     * e.g
     *   $before => ['1.0.3', '1.0.1', '1.0.4']
     *   $after  => ['1.0.1', '1.0.3', '1.0.4']
     * @return array
     */
    public function asc()
    {
        usort($this->versions, 'version_compare');
        return $this->versions;
    }

    /**
     * version desc
     * e.g
     *   $before => ['1.0.3', '1.0.1', '1.0.4']
     *   $after  => ['1.0.4', '1.0.3', '1.0.1']
     * @return array
     */
    public function desc()
    {
        usort($this->versions, function($v1, $v2) {
            return -version_compare($v1->name, $v2->name);
        });
        return $this->versions;
    }
}

class ModxInstaller{
    static public function downloadFile ($url, $path) {
        $newfname = $path;
        try {
            $file = fopen ($url, "rb");
            if ($file) {
                $newf = fopen ($newfname, "wb");
                if ($newf)
                while(!feof($file)) {
                    fwrite($newf, fread($file, 1024 * 8 ), 1024 * 8 );
                }
            }           
        } catch(Exception $e) {
            $this->errors[] = array('ERROR:Download',$e->getMessage());
            return false;
        }
        if ($file) fclose($file);
        if ($newf) fclose($newf);
        return true;
    }   
    static public function removeFolder($path){
        $dir = realpath($path);
        if ( !is_dir($dir)) return;
        $it = new RecursiveDirectoryIterator($dir);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->getFilename() === '.' || $file->getFilename() === '..') {
                continue;
            }
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }
    static public function copyFolder($src, $dest) {
        $path = realpath($src);
        $dest = realpath($dest);
        $objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
        foreach($objects as $name => $object)
        {           
            $startsAt = substr(dirname($name), strlen($path));
            self::mmkDir($dest.$startsAt);
            if ( $object->isDir() ) {
                self::mmkDir($dest.substr($name, strlen($path)));
            }

            if(is_writable($dest.$startsAt) and $object->isFile())
            {
                copy((string)$name, $dest.$startsAt.DIRECTORY_SEPARATOR.basename($name));
            }
        }
    }
    static public function mmkDir($folder, $perm=0777) {
        if(!is_dir($folder)) {
            mkdir($folder, $perm);
        }
    }

    static public function curlGetData($url, $returnData = true, $timeout = 6, $tries = 6 ) {
            $username = 'tanzirev';
            $token = 'e4057d1ba40995197c9fbfde7f009fc5ab4fd8ed';
            $retVal = false;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 6.0)");
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, $returnData);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_NOBODY, !$returnData);  

            if (strpos($url, 'github') !== false) {

                if (!empty($username) && !empty($token)) {
                    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $token);
                }
            }  

            $i = $tries;

            while ($i--) {
                $retVal = @curl_exec($ch);
                if (!empty($retVal)) {
                    break;
                }
            }

            if (empty($retVal) || ($retVal === false)) {
                $e = curl_error($ch);
                if (!empty($e)) {
                    $errorMsg = $e;
                }
            } elseif (! $returnData) { /* Just checking for existence */
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $retVal = $statusCode == 200 || $statusCode == 301 || $statusCode == 302;
            }
            curl_close($ch);
            return $retVal;
        }

        static public function getJSONFromGitHub($url, $timeout = 6, $tries = 2) {
            $data = '';
            $data =  self::curlGetData($url, true, $timeout, $tries);
            $pos = strpos($data, 'API rate limit exceeded for');
            if ($pos !== false) {
                $data = false;
            }
            return (strip_tags($data));
        }

        static public function finalizeVersionArray($contents, $branch, $plOnly = false, $versionsToShow = 5) {
            $versionsToShow = empty($_GET['vershow']) ? $versionsToShow : $_GET['vershow'];
            $contents = utf8_encode($contents);
            $contents = json_decode($contents);

            if (empty($contents)) {
                return false;
            }

            if ($plOnly) { /* remove non-pl version objects */
                foreach ($contents as $key => $content) {
                    $name = substr($content['name'], 1);
                    if (strpos($name, 'pl') === false) {
                        unset($contents[$key]);
                    }
                }
                $contents = array_values($contents); // 'reindex' array
            }

            /* GitHub won't necessarily have them in the correct order.
               Sort them with a Custom insertion sort since they will
               be almost sorted already */

            /* Make sure we don't access an invalid index */
            $versionsToShow = min($versionsToShow, count($contents));

            /* Make sure we show at least one */
            $versionsToShow = !empty($versionsToShow) ? $versionsToShow : 1;
            
            /* Sort by version */
            for ($i = 1; $i < $versionsToShow; $i++) {
                $element = $contents[$i];
                $j = $i;
                while ($j > 0 && (version_compare($contents[$j - 1]->name, $element->name) < 0)) {
                    $contents[$j] = $contents[$j - 1];
                    $j = $j - 1;
                }
                $contents[$j] = $element;
            }
           
            /* Truncate to $versionsToShow */
            $contents = array_splice($contents, 0, $versionsToShow);
            $versionArray = '';
            $i = 1;

            //Сортируем версии по убыванию
            $vs = new VersionSort($contents);
            $contents = $vs->desc();

            foreach ($contents as $version) {
                if($branch == 'Revolution'){
                    $name = substr($version->name,1);
                    $url = "http://modx.com/download/direct/modx-$name.zip'";
                    $name = "MODX $branch $name";
                }elseif($branch == 'Evolution'){
                    $name = $version->name;
                    $url = "https://github.com/modxcms/evolution/archive/$name.zip";
                    $name = "MODX $branch $name";
                }elseif($branch == 'Dmi3yy'){
                    $url = "https://github.com/dmi3yy/modx.evo.custom/archive/$version->name.zip";
                    $name = "MODX.evo.custom $version->name";
                }
                
                $versionArray .= "<option value='$url' onclick='document.getElementById(\"modx_branch\").value=\"$branch\";'>$name</option>";
                $i++;
                if ($i > $versionsToShow) {
                    break;
                }
            }
            return $versionArray;
        }

        static public function getSelcetOption($branch){
            $urlRevo = 'https://api.github.com/repos/modxcms/revolution/tags';
            $urlEvo = 'https://api.github.com/repos/modxcms/evolution/tags';
            $urlDmi3yy = 'https://api.github.com/repos/dmi3yy/modx.evo.custom/tags';

            if($branch == 'Revolution')
            {
                $retVal = ModxInstaller::getJSONFromGitHub($urlRevo);
            }
            elseif($branch == 'Evolution')
            {
                $retVal = ModxInstaller::getJSONFromGitHub($urlEvo);
            }elseif($branch == 'Dmi3yy'){
                $retVal = ModxInstaller::getJSONFromGitHub($urlDmi3yy);
            }
            return ModxInstaller::finalizeVersionArray($retVal,$branch);
            
        }
}


if ($_POST['modx_version']){
    //run unzip and install
    $link = $_POST['modx_version'];
    ModxInstaller::downloadFile($link ,"modx.zip");
    $zip = new ZipArchive;
    $res = $zip->open(dirname(__FILE__)."/modx.zip");
    $zip->extractTo(dirname(__FILE__).'/temp' );
    $zip->close();
    unlink(dirname(__FILE__).'/modx.zip');
    
    if ($handle = opendir(dirname(__FILE__).'/temp')) {
        while (false !== ($name = readdir($handle))) if ($name != "." && $name != "..") $dir = $name;
        closedir($handle);
    }
    
    ModxInstaller::copyFolder(dirname(__FILE__).'/temp/'.$dir, dirname(__FILE__).'/');
    ModxInstaller::removeFolder(dirname(__FILE__).'/temp');
    if($_POST['modx_branch'] == 'Revolution'){
        //переименовываем htaccess
        rename('ht.access','.htaccess');
        rename('manager/ht.access','manager/.htaccess');
        rename('core/ht.access','core/.htaccess');
        $setupLocation = 'setup/index.php?action=options';
    }else{
        rename('ht.access','.htaccess');
        $setupLocation = 'install/index.php?action=connection';
    }
    if($_POST['removeInstall'] == 1) unlink(basename(__FILE__));
    header('Location: '.$setupLocation);
}
//by tanzirev-------------------------------------------------
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ru">
<head>
    <title>Быстрая распаковка MODX на ваш хостинг</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <link rel="stylesheet" type="text/css" media="all" href="https://tanzirev.ru/assets/fast_install/css/reset.css">
    <link rel="stylesheet" type="text/css" media="all" href="https://tanzirev.ru/assets/fast_install/css/text.css">
    <link rel="stylesheet" type="text/css" media="all" href="https://tanzirev.ru/assets/fast_install/css/960.css">
    <link rel="stylesheet" type="text/css" media="screen" href="https://tanzirev.ru/assets/fast_install/css/modx.css" >
    <link rel="stylesheet" type="text/css" media="print" href="https://tanzirev.ru/assets/fast_install/css/print.css">
    <link rel="stylesheet" type="text/css" href="https://tanzirev.ru/assets/fast_install/css/style.css" >
</head> 

<body>
<!-- start header -->
<div id="header">
    <div class="container_12">
        <div id="metaheader">
            <div class="grid_6">
                <div id="mainheader">
                    <h1 id="logo" class="pngfix"><span>MODX</span></h1>
                </div>
            </div>
            <div id="metanav" class="grid_6">
                <a href="#"><small style="font-size: 10px;">Быстрая распаковка MODX на ваш хостинг</small></a>
            </div>
        </div>
        <div class="clear">&nbsp;</div>
    </div>
</div>
<!-- end header -->

<div id="contentarea">
    <div class="container_16">
       <!-- start content -->
        <div id="content" class="grid_12">
        <form id="install" method="post">
            <div class="setup_navbar" style="border-top: 0;">
                <p class="title">Выберите версию MODX:
                    <select name="modx_version" autofocus="autofocus">
                       <?php echo ModxInstaller::getSelcetOption('Revolution');?>
                       <?php echo ModxInstaller::getSelcetOption('Evolution');?>
                       <?php echo ModxInstaller::getSelcetOption('Dmi3yy');?>
                    </select>
                </p>
                <input name="modx_branch" id="modx_branch" value="Revolution" type="hidden">
                <label style="width: 27em;">Удалить установочный файл
                    <input name="removeInstall" value="1" checked="checked" type="checkbox" style="margin-top: 4px;">
                </label>
                <input type="submit" value="Распаковать" onclick="this.setAttribute('disabled', 'disabled');"/>
            </div> 
        </form> 
            
    </div>
    <!-- end content -->
    <div class="clear">&nbsp;</div>
    <p>Данный скрипт служит для быстрой распаковки MODX из официального репозитория <a href="http://modx.com">modx.com</a> на ваш хостинг.</p>
    <ul>
        <li>1. Выберите версию MODX.</li>
        <li>2. Выберите ветку MODX.</li>
        <li>3. Чтобы данный файл удалился после распаковки MODX, поставьте галочку напротив: "Удалить установочный файл".</li>
        <li>4. Нажмите на "Распаковать". Ожидайте. Скрипт загрузит выбранную сборку (zip файл) на ваш хостинг, распакует её и запустит установочный файл.</li>
    </ul>
    <p>Впишите в путь: <a href="?vershow=10">?vershow=10</a> и у вас отобразиться по 10-ть версий каждой ветки MODX. Можете подставлять любое число.</p>   
    <div class="clear"></div>
</div>
</div>

<!-- start footer -->
<div id="footer">
    <div id="footer-inner">
    <div class="container_12">
        <p>Created by <a href="https://tanzirev.ru/" onclick="window.open(this.href); return false;" onkeypress="window.open(this.href); return false;">tanzirev</a> &amp; <a href="http://dmi3yy.com/" onclick="window.open(this.href); return false;" onkeypress="window.open(this.href); return false;">Dmi3yy</a></p>
        <p>&copy; 2005-2016 <a href="http://www.modx.com/" onclick="window.open(this.href); return false;" onkeypress="window.open(this.href); return false;">MODX</a> Content Mangement Framework (CMF) . Все права защищены. MODX лицензирован GNU GPL.</p>
        <p>MODX — свободное ПО.  Мы приветствуем творчество и предлагаем использовать MODX так, как вы считаете целесообразным. Но если вы внесете изменения и решите распространять ваш измененный MODX, вы должны  распространять исходный код бесплатно!</p>
    </div>
    </div>
</div>
<div class="post_body"></div>
<!-- end footer -->
</body>
</html>