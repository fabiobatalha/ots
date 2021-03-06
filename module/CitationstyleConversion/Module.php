<?php

namespace CitationstyleConversion;

use CitationstyleConversion\Model\Converter\Pandoc;
use CitationstyleConversion\Model\Citationstyles;

class Module
{
    /**
     * Get config
     *
     * @return array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Get autoloader config
     *
     * @return array
     */
    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\ClassMapAutoloader' => array(
                __DIR__ . '/autoload_classmap.php'
            ),
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    /**
     * Get service config
     *
     * @return array
     */
    public function getServiceConfig()
    {
        return array(
            'factories' => array(
                'CitationstyleConversion\Model\Converter\Pandoc' => function($sm)
                {
                    $config = $sm->get('Config');
                    $logger = $sm->get('Logger');
                    if (!isset($config['conversion']['citationstyle']['pandoc'])) {
                        throw new \Exception('Pandoc configuration is missing');
                    }
                    $config = $config['conversion']['citationstyle']['pandoc'];

                    return new Pandoc($config, $logger);
                },
                'CitationstyleConversion\Model\Citationstyles' => function($sm)
                {
                    $config = $sm->get('Config');
                    $logger = $sm->get('Logger');
                    $cache = $sm->get('Cache');
                    if (!isset($config['conversion']['citationstyle']['citationstyles'])) {
                        throw new \Exception('Citationstyle repository configuration is missing');
                    }
                    $config = $config['conversion']['citationstyle']['citationstyles'];

                    return new Citationstyles($config, $logger, $cache);
                },
            ),
        );
    }
}
