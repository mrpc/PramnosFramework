<?php

namespace Pramnos\General;

/**
 * Legacy Validator class, maintained for backward compatibility.
 * Redirects all calls to the modern Validation system.
 * 
 * @package     PramnosFramework
 * @subpackage  Validation
 * @deprecated Use \Pramnos\Validation\Validator instead.
 */
class Validator extends \Pramnos\Validation\Validator
{
    /**
     * @inheritdoc
     */
    public static function validate(
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = []
    ): array {
        self::triggerDeprecation();
        return parent::validate($data, $rules, $messages, $attributes);
    }

    /**
     * Trigger a deprecation notice.
     */
    protected static function triggerDeprecation()
    {
        trigger_error(
            'Pramnos\General\Validator is deprecated. Please use \Pramnos\Validation\Validator instead.', 
            E_USER_DEPRECATED
        );
    }

    /**
     * @inheritdoc
     */
    public static function checkEmail($email)
    {
        self::triggerDeprecation();
        return parent::checkEmail($email);
    }

    /**
     * @inheritdoc
     */
    public static function isJson($string): bool
    {
        self::triggerDeprecation();
        return parent::isJson($string);
    }

    /**
     * @inheritdoc
     */
    public static function checkLink($url)
    {
        self::triggerDeprecation();
        return parent::checkLink($url);
    }

    /**
     * Factory function for legacy support.
     *
     * @return static
     */
    public static function &getInstance()
    {
        self::triggerDeprecation();
        return parent::getInstance();
    }
}