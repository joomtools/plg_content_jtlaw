<?php
/**
 * Plugin for Joomla! 2.5 and higher
 * Insert and cache HTML files from Your own Server
 *
 * @package    Joomla.Plugin
 * @subpackage Content.jtlaw
 * @author     Guido De Gobbis <guido.de.gobbis@joomtools.de>
 * @copyright  2015 JoomTools
 * @license    GNU/GPLv3 <http://www.gnu.org/licenses/gpl-3.0.de.html>
 * @link       http://joomtools.de
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

jimport('joomla.plugin.plugin');
jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');

/**
 * Class PlgContentJtlaw
 *
 * Insert and cache HTML files from Your own Server
 *
 * @package    Joomla.Plugin
 * @subpackage Content.jtlaw
 */
class PlgContentJtlaw extends JPlugin
{
    protected $plgParams = null;
    protected $message   = null;
    protected $buffer    = null;

    public function onContentPrepare($context, &$article, &$params, $limitstart)
    {
        $app = JFactory::getApplication();
        $this->loadLanguage('plg_content_jtlaw');

        if (!$app->isSite())
        {
            return;
        }

        if (strpos($article->text, '{jtlaw ') === false)
        {
            return;
        }

        $cachePath                    = JPATH_PLUGINS . '/content/jtlaw/cache';
        $this->plgParams              = $this->params->toArray();
        $this->plgParams['server']    = rtrim($this->plgParams['server'], '\/');
        $this->plgParams['cachetime'] = $this->plgParams['cachetime'] * 60;

        if ($this->plgParams['server'] == '')
        {
            $this->message['warning'][] = JText::_('PLG_CONTENT_JTLAW_WARNING_NO_SERVER');
        }

        if ($this->plgParams['cachetime'] < '7200')
        {
            $this->plgParams['cachetime'] = '7200';
        }

        if (JDEBUG)
        {
            $this->plgParams['cachetime'] = '0';
        }

        if (!JFolder::exists($cachePath))
        {
            JFolder::create($cachePath);
        }

        $plgCalls = $this->getPlgCall($article->text);

        foreach ($plgCalls as $plgCall)
        {
            $fileName  = strtolower($plgCall[3]) . '.html';
            $cacheFile = $cachePath . '/' . $fileName;

            if ($checkFile = JFile::exists($cacheFile))
            {
                $checkFile = $this->getFileTime($cacheFile);
            }

            $this->setBuffer($cacheFile, $checkFile);
            $article->text = str_replace($plgCall[0], $this->buffer, $article->text);
            $this->buffer  = null;
        }

        if ($this->message !== null)
        {
            foreach($this->message as $type => $msgs)
            {
                if ($type == 'error')
                {
                    $msgs[] = JText::_('PLG_CONTENT_JTLAW_ERROR_CHECKLIST');
                }

                $msg = implode('<br />', $msgs);
                $app->enqueueMessage($msg, $type);
            }
        }
    }

    /**
     * Find all plugin call's in $text and return them as array
     *
     * @param string $text Text with plugin call's
     *
     * @return array       All matches found in $text
     */
    protected function getPlgCall($text)
    {
        $regex = '@(<(\w*+)[^>]*>|){jtlaw\s(.*)}(</\2>|)@siU';
        $p1    = preg_match_all($regex, $text, $matches, PREG_SET_ORDER);

        if ($p1)
        {
            foreach ($matches as $key => $match)
            {
                $closeTag = ($match[2] != '') ? strpos($match[4], $match[2]) : true;

                if (!$closeTag)
                {
                    $matches[$key][0] = str_replace($match[1], '', $match[0]);
                }
            }

            return $matches;
        }

        return array();
    }

    /**
     * Check to see if the cache file is up to date
     *
     * @param string $file Filename with absolute path
     *
     * @return bool return true if cached file is up to date
     */
    protected function getFileTime($file)
    {
        $time      = time();
        $cacheTime = $this->plgParams['cachetime'];
        $fileCtime = filectime($file);
        $fileMtime = filemtime($file);
        $fileTime  = ($fileCtime < $fileMtime)
            ? $fileMtime
            : $fileCtime;

        $control = $time - $fileTime;

        if ($control >= $cacheTime)
        {
            return false;
        }

        return true;
    }

    /**
     * Load HTML file from Server or get cached file
     *
     * @param string     $cacheFile Filename with absolute path
     * @param bool|false $checkFile
     *
     * @return bool
     */
    protected function setBuffer($cacheFile, $checkFile = false)
    {
        $server   = $this->plgParams['server'];
        $fileName = basename($cacheFile);

        if ($checkFile)
        {
            $this->buffer = @file_get_contents($cacheFile);
        }
        else
        {
            $http = JHttpFactory::getHttp();
            $data = $http->get($server . '/' . $fileName);

            if ($data->code !== 200)
            {
                if (JFile::exists($cacheFile))
                {
                    $this->setBuffer($cacheFile, true);
                }
                else
                {
                    $this->message['error'][] = JText::sprintf(
                        'PLG_CONTENT_JTLAW_ERROR_NO_CACHE_SERVER', $fileName
                    );

                    return false;
                }
            }

            $search  = array('@<br>@i');
            $replace = array('<br />');
            $result  = preg_replace($search, $replace, $data->body);

            JFile::delete($cacheFile);
            JFile::write($cacheFile, $result);

            $this->buffer = $result;
        }

        return true;
    }
}

