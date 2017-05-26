<?php
/**
 * This file is part of a FireGento e.V. module.
 *
 * This FireGento e.V. module is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 3 as
 * published by the Free Software Foundation.
 *
 * This script is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * PHP version 5
 *
 * @category  FireGento
 * @package   FireGento_Logger
 * @author    FireGento Team <team@firegento.com>
 * @copyright 2013 FireGento Team (http://www.firegento.com)
 * @license   http://opensource.org/licenses/gpl-3.0 GNU General Public License, version 3 (GPLv3)
 */
require 'aws_php-sdk_v3' . DS . 'aws-autoloader.php';

/**
 * $mapping is defined inside the aws-autoloader.php file above.
 * This thing is needed because the autoloader registered inside the file
 * is added at the end of autoload stack, so we need to register it passing the
 * $prepend param with true
*/
spl_autoload_register(function ($class) use ($mapping) {
    if (isset($mapping[$class])) {
        require $mapping[$class];
    }
}, true, true);

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;

/**
 * Model for CloudWatch Log logging
 *
 * @category FireGento
 * @package  FireGento_Logger
 * @author   Gennaro Vietri <gennaro.vietri@bitbull.it>
 */
class FireGento_Logger_Model_Cloudwatch extends Zend_Log_Writer_Abstract
{
    /**
     * @var array Contains configuration options.
     */
    protected $_options = array();

    /**
     * @var bool Indicates if backtrace should be added to the Log Message.
     */
    protected $_enableBacktrace = false;

    /**
     * Setter for class variable _enableBacktrace
     *
     * @param bool $flag Flag for Backtrace
     */
    public function setEnableBacktrace($flag)
    {
        $this->_enableBacktrace = $flag;
    }

    /**
     * Class constructor
     *
     * @param  string $filename Filename
     * @return FireGento_Logger_Model_Cloudwatch
     */
    public function __construct($filename)
    {
        /* @var $helper FireGento_Logger_Helper_Data */
        $helper = Mage::helper('firegento_logger');

        $this->_options['FileName'] = basename($filename);
        $this->_options['LogGroup'] = $helper->getLoggerConfig('cloudwatch/log_group');
        $this->_options['Region'] = $helper->getLoggerConfig('cloudwatch/region');
        $this->_options['IamKey'] = $helper->getLoggerConfig('cloudwatch/iam_key');
        $this->_options['IamSecret'] = $helper->getLoggerConfig('cloudwatch/iam_secret');

        $this->_timeout = $helper->getLoggerConfig('cloudwatch/timeout');
        
        return $this;
    }

    /**
     * Builds a JSON Message that will be sent to CloudWatch.
     *
     * @param  array $event           A Magento Log Event.
     * @param  bool  $enableBacktrace Indicates if a backtrace should be added to the log event.
     * @return string A JSON structure representing the message.
     */
    protected function BuildJSONMessage($event, $enableBacktrace = false)
    {
        /** @var $event FireGento_Logger_Model_Event */
        Mage::helper('firegento_logger')->addEventMetadata($event, null, $enableBacktrace);

        $fields = array();
        $fields['Level'] = $event->getPriority();
        $fields['FileName'] = $event->getFile();
        $fields['LineNumber'] = $event->getLine();
        $fields['StoreCode'] = $event->getStoreCode();
        $fields['Pid'] = getmypid();
        $fields['TimeElapsed'] = $event->getTimeElapsed();
        $fields['Host'] = php_uname('n');
        $fields['TimeStamp'] = date(DATE_ISO8601, strtotime($event->getTimestamp()));
        $fields['Facility'] = $this->_options['FileName'];
        $fields['Message'] = $event->getMessage();

        if ($event->getBacktrace()) {
            $fields['Backtrace'] = $event->getBacktrace();
        }

        foreach (array('getRequestMethod', 'getRequestUri', 'getRemoteIp', 'getHttpUserAgent','getHttpHost','getHttpCookie','getSessionData') as $method) {
            if (is_callable(array($event, $method)) && $event->$method()) {
                $fields[lcfirst(substr($method, 3))] = $event->$method();
            }
        }

        return json_encode($fields);
    }

    /**
     * Sends a JSON Message to CloudWatch.
     *
     * @param  string $message The JSON-Encoded Message to be sent.
     * @throws Zend_Log_Exception
     * @return bool True if message was sent correctly, False otherwise.
     */
    protected function PublishMessage($message)
    {
        $options = [
            'region'  => $this->_options['Region'],
            'version' => 'latest',
            'credentials' => [
                'key'    => $this->_options['IamKey'],
                'secret' => $this->_options['IamSecret'],
            ]
        ];

        $client = new CloudWatchLogsClient($options);
        date_default_timezone_set('UTC');

        $logGroupName = $this->_options['LogGroup'];
        $logStreamName = $this->_options['FileName'];

        try {

            $logStream = $client->describeLogStreams(array(
                'logGroupName' => $logGroupName,
                'logStreamNamePrefix' => $logStreamName,
            ));

            $result = $client->putLogEvents(array(
                'logGroupName' => $logGroupName,
                'logStreamName' => $logStreamName,
                'logEvents' => array(
                    array(
                        'message' => $message,
                        'timestamp' => round(microtime(true) * 1000),
                    )
                )
            ));
        } catch (CloudWatchLogsException $e) {

            throw new Zend_Log_Exception('Error occurred posting log message to CloudWatch.'
                . "\n" . 'Error code: ' . $e->getAwsErrorCode()
                . "\n" . 'Message: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Places event line into array of lines to be used as message body.
     *
     * @param  array $event Event data
     * @return bool True if message was sent correctly, False otherwise.
     */
    protected function _write($event)
    {
        $event = Mage::helper('firegento_logger')->getEventObjectFromArray($event);
        $message = $this->BuildJSONMessage($event, $this->_enableBacktrace);
        return $this->PublishMessage($message);
    }

    /**
     * Satisfy newer Zend Framework
     *
     * @param  array|Zend_Config $config Configuration
     * @return void|Zend_Log_FactoryInterface
     */
    public static function factory($config)
    {

    }
}
