<?php
/**
 * @Copyright  JoomTools.de
 * @package    JT - Law - Plugin for Joomla! 3.4.5 and higher
 * @author     Guido De Gobbis
 * @link       http://www.joomtools.de
 * @license    GNU/GPL <http://www.gnu.org/licenses/>
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

jimport('joomla.plugin.plugin');
jimport('joomla.filesystem.file');

class plgContentJtlaw extends JPlugin
{
    /* Plugin-Params */
    protected $pluginParams = null;

    /* Ausgabebuffer */
    protected $_buffer = null;

    /* Regex fürt Pluginaufruf im Content */
    protected $regex = '#(<(\w+)[^>]*>|){jtlaw (.*)}(</\\2+>|)#siU';

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->loadLanguage('plg_content_jtlaw');
    }

    public function onContentPrepare($context, &$article, &$params, $limitstart)
    {
        $app = JFactory::getApplication();
        if (!$app->isSite())
        {
            return;
        }

        /* Prüfen ob Plugin-Platzhalter im Text ist */
        if (JString::strpos($article->text, '{jtlaw ') === false)
        {
            return;
        }

        //$start = microtime(true);

        /* Plugin-Parameter auslesen */
        $this->pluginParams              = $this->params->toArray();
        $this->pluginParams['server']    = rtrim($this->pluginParams['server'], '\/');
        $this->pluginParams['cachePath'] = JPATH_PLUGINS . '/content/jtlaw/cache';
        $this->pluginParams['cachetime'] = $this->pluginParams['cachetime'] * 60;

        if ($this->pluginParams['server'] == '')
        {
            $app->enqueueMessage(JText::_('PLG_CONTENT_JTLAW_MESSAGE_NO_SERVER'), 'warning');

            return;
        }

        if ($this->pluginParams['cachetime'] < '7200')
        {
            $this->pluginParams['cachetime'] = '7200';
        }

        $cachetime = $this->pluginParams['cachetime'];
        //$cachetime = 0;
        $cachePath = $this->pluginParams['cachePath'];
        $filename  = $this->_getFilename($article);

        if (!JFolder::exists($cachePath))
        {
            JFolder::create($cachePath);
        }

        /* Schleife zur Abarbeitung mehrer Aufrufe in einem Beitrag */
        foreach ($filename as $_file)
        {
            $file = strtolower($_file[3]) . '.html';

            /* Cacheprüfung
             * ist die gesuchte Datei schon im Cache
             * 	NEIN - Datei von Janolaw holen
             * 	JA - Cachzeit prüfen
             *
             * ist die Cachezeit abgelaufen
             * 	JA - Datei löschen und von Janolaw neu holen
             *
             * SONST - Datei auslesen und in _buffer schreiben
             */

            if ($checkFile = JFile::exists($cachePath . '/' . $file)) // Datei wurde im Cache gefunden
            {
                /* Aktualität der Datei im Cache */
                $checkFile = $this->_getFileTime($file);
            }

            /* Datei von Janolaw, oder aus dem Cache abholen */
            $this->_getFile($file, $checkFile);

            /* Plugin-Aufruf durch HTML-Ausgabe ersetzen */
            $article->text = str_replace($_file[0], $this->_buffer, $article->text);

            $this->_buffer = null;
            //$finish        = microtime(true);
            //$app->enqueueMessage('Verbrauchte Zeit: ' . number_format(($finish - $start), 4, ',', '.') . ' sek.', 'info');
        }
    }

    /* Methode zum Auslesen und auswerten des Pluginaufrufes */
    protected function _getFilename(&$article)
    {
        $return = false;
        $p1     = preg_match_all($this->regex, $article->text, $matches, PREG_SET_ORDER);

        if ($p1 !== false)
        {
            $return = $matches;
        }

        return $return;
    }

    /* Methode zur Prüfung der Erstellungszeit der Datei */
    protected function _getFileTime($file)
    {
        /* aktueller Timestamp */
        $timestamp = time();
        $return    = true;
        $cachetime = $this->pluginParams['cachetime'];
        $cachePath = $this->pluginParams['cachePath'];

        /* Timestamp der Datei */
        $fileCtimestamp = filectime($cachePath . '/' . $file);
        $fileMtimestamp = filemtime($cachePath . '/' . $file);
        $fileTimestamp  = ($fileCtimestamp < $fileMtimestamp) ? $fileMtimestamp : $fileCtimestamp;
        $normal         = date('d.m.Y-H:i:s', $fileTimestamp);

        /* Datei - Cachezeit */
        $dateiCachetime = $timestamp - $fileTimestamp;

        /* Prüfung ob Cachzeit abgelaufen ist */
        if ($dateiCachetime >= $cachetime)
        {
            $return = false;
        }

        return $return;
    }

    /* Datei von Janolaw, oder aus dem Cache abholen */
    protected function _getFile($file, $checkFile)
    {
        $cachePath = $this->pluginParams['cachePath'];
        $server    = $this->pluginParams['server'];

        if ($checkFile)
        {
            $this->_buffer = @file_get_contents($cachePath . '/' . $file);
        }
        else
        {

            $http = JHttpFactory::getHttp();
            $data = $http->get($server . '/' . $file);

            if ($data->code !== 200)
            {
                if (JFile::exists($cachePath . '/' . $file))
                {
                    $this->_getFile($file, true);

                    return;
                }
                else
                {
                    //Fehler ausgeben
                    JFactory::getApplication()->enqueueMessage(JText::sprintf('PLG_CONTENT_JTLAW_MESSAGE_NO_CACHE_NO_SERVER', $file), 'error');

                    return;
                }
            }

            $result = $data->body;

            /* <br> in <br /> umwandeln */
            $result = preg_replace('#<br>#i', '<br />', $result);

            $this->_buffer = $result;

            JFile::delete($cachePath . '/' . $file);
            JFile::write($cachePath . '/' . $file, $result);
        }
    }

}

