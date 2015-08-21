<?php

namespace Pramnos\Framework;

/**
 * IoC Container object class
 * @package     PramnosFramework
 * @copyright   2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Container extends Base
{

    private $classes = array();
    private $sigletons = array();
    private $instances = array();


    /**
     * Register a class type to a closure
     * @param  string    $class
     * @param  \Closure  $return
     * @return \Pramnos\Framework\Container
     */
    public function register($class, $return)
    {
        if (!$this->isRegistered($class)) {
            $this->classes[$class] = $return;
        }

        return $this;
    }

    /**
     * Check if a class is registered in the container
     * @param  string $class
     * @return boolean
     */
    protected function isRegistered($class)
    {
        if (isset($this->classes[$class]) || isset($this->sigletons[$class])) {
            return true;
        }

        return false;
    }

    /**
     * Remove a registered class
     * @param  string $class
     * @return \Pramnos\Framework\Container
     */
    public function deRegister($class)
    {
        if (isset($this->classes[$class])) {
            unset($this->classes[$class]);
        }
        if (isset($this->sigletons[$class])) {
            unset($this->sigletons[$class]);
        }
        if (isset($this->instances[$class])) {
            unset($this->instances[$class]);
        }

        return $this;
    }

    /**
     * Register a class type to a closure. This class will have only one
     * instance.
     * @param  string    $class
     * @param  \Closure  $return
     * @return \Pramnos\Framework\Container
     */
    public function sigleton($class, $return)
    {
        if (!$this->isRegistered($class)) {
            $this->sigletons[$class] = $return;
        }

        return $this;
    }

    /**
     * Get the registered object
     * @param  string $class
     * @return object
     */
    public function get($class)
    {
        if (!$this->isRegistered($class)) {
            $this->discoverClass($class);
        }
        if (isset($this->sigletons[$class])) {
            return $this->getSigleton($class);
        }
        if (isset($this->classes[$class])) {
            return $this->factory($class);
        }
    }

    protected function getSigleton($class)
    {
        if (is_callable($this->sigletons[$class])) {
            return $this->sigletons[$class]($this);
        }
    }

    /**
     * Create a new instance of a registeredclass
     * @param  string $class
     * @return object
     */
    protected function factory($class)
    {
        if (is_callable($this->classes[$class])) {
            return $this->classes[$class]($this);
        }
    }

    protected function discoverClass($class)
    {
        if (!class_exists($class)) {
            throw new Exception($class . ' class is not defined.');
        }
        $reflectedClass = new \ReflectionClass($class);
        $constructor = $reflectedClass->getConstructor();
        if ($constructor == null) {
            $this->register($class, $class);
        } else {
            $parameters = $constructor->getParameters();
        }
        return $this;
    }
}