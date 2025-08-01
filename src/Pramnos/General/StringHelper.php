<?php 

namespace Pramnos\General;

class StringHelper
{

    /**
     * Cache for irregular plurals
     * @var array
     */
    private static $irregularPlurals = [
        'child' => 'children',
        'person' => 'people',
        'man' => 'men',
        'woman' => 'women',
        'foot' => 'feet',
        'tooth' => 'teeth',
        'goose' => 'geese',
        'mouse' => 'mice',
        'ox' => 'oxen',
        'leaf' => 'leaves',
        'life' => 'lives',
        'knife' => 'knives',
        'wife' => 'wives',
        'half' => 'halves',
        'self' => 'selves',
        'elf' => 'elves',
        'loaf' => 'loaves',
        'potato' => 'potatoes',
        'tomato' => 'tomatoes',
        'cactus' => 'cacti',
        'focus' => 'foci',
        'fungus' => 'fungi',
        'nucleus' => 'nuclei',
        'syllabus' => 'syllabi',
        'analysis' => 'analyses',
        'diagnosis' => 'diagnoses',
        'thesis' => 'theses',
        'crisis' => 'crises',
        'phenomenon' => 'phenomena',
        'criterion' => 'criteria',
        'datum' => 'data',
        'medium' => 'media',
        'supply' => 'supplies',
        'quiz' => 'quizzes'
    ];

    /**
     * Singular words ending in 's'
     * @var array
     */
    private static $singularWithS = [
        'news', 'lens', 'species', 'series', 'means', 'swiss', 'kudos'
    ];

    /**
     * Get plural form of a word
     * 
     * @param string $singular Singular form
     * @return string Plural form
     */
    public static function pluralize($singular)
    {
        $singular = strtolower($singular);
        
        // Return as is if already plural
        if (self::isPlural($singular)) {
            return $singular;
        }
        
        // Check for irregular plurals
        if (isset(self::$irregularPlurals[$singular])) {
            return self::$irregularPlurals[$singular];
        }
        
        // Words ending in -y: change y to ies unless the word ends with a vowel + y
        if (preg_match('/[^aeiou]y$/i', $singular)) {
            return substr($singular, 0, -1) . 'ies';
        }
        
        // Words ending in -is: change to -es
        if (substr($singular, -2) === 'is') {
            return substr($singular, 0, -2) . 'es';
        }
        
        // Words ending in -us: change to -i for certain Latin words
        if (substr($singular, -2) === 'us' && strlen($singular) > 3) {
            $latinWords = ['cactus', 'focus', 'fungus', 'nucleus', 'stimulus', 'alumnus'];
            if (in_array($singular, $latinWords)) {
                return substr($singular, 0, -2) . 'i';
            }
        }
        
        // Words ending in -ch, -sh, -ss, -x, -z, -o: add -es
        if (preg_match('/(ch|sh|ss|x|z|o)$/i', $singular)) {
            return $singular . 'es';
        }
        
        // Words ending in -f or -fe: change to -ves for some words
        if (preg_match('/(f|fe)$/i', $singular)) {
            $fWords = ['leaf', 'life', 'knife', 'wife', 'half', 'self', 'elf', 'loaf', 'shelf'];
            if (in_array($singular, $fWords) || in_array(substr($singular, 0, -2), $fWords)) {
                if (substr($singular, -1) === 'f') {
                    return substr($singular, 0, -1) . 'ves';
                } else { // -fe
                    return substr($singular, 0, -2) . 'ves';
                }
            }
        }
        
        // Default: add -s
        return $singular . 's';
    }
    
    /**
     * Get singular form of a word
     * 
     * @param string $plural Plural form
     * @return string Singular form
     */
    public static function singularize($plural)
    {
        $plural = strtolower($plural);
        
        // Return as is if already singular
        if (!self::isPlural($plural)) {
            return $plural;
        }
        
        // Check for words that look plural but are singular
        if (in_array($plural, self::$singularWithS)) {
            return $plural;
        }
        
        // Check for irregular plurals
        $singulars = array_flip(self::$irregularPlurals);
        if (isset($singulars[$plural])) {
            return $singulars[$plural];
        }
        
        // Words ending in -ies: change to -y
        if (substr($plural, -3) === 'ies') {
            return substr($plural, 0, -3) . 'y';
        }
        
        // Words ending in -es: remove -es
        if (substr($plural, -2) === 'es') {
            // Special cases like -xes, -ches, -sses
            if (preg_match('/(ch|sh|ss|x|z|o)es$/i', $plural)) {
                return substr($plural, 0, -2);
            }
            return substr($plural, 0, -2);
        }
        
        // Words ending in -i: change to -us (Latin)
        if (substr($plural, -1) === 'i') {
            $possibleSingulars = ['cacti' => 'cactus', 'foci' => 'focus', 'fungi' => 'fungus', 
                                 'nuclei' => 'nucleus', 'stimuli' => 'stimulus', 'alumni' => 'alumnus'];
            if (isset($possibleSingulars[$plural])) {
                return $possibleSingulars[$plural];
            }
        }
        
        // Words ending in -ves: change to -f or -fe
        if (substr($plural, -3) === 'ves') {
            $root = substr($plural, 0, -3);
            $fWords = ['lea' => 'leaf', 'li' => 'life', 'kni' => 'knife', 
                      'wi' => 'wife', 'hal' => 'half', 'sel' => 'self', 
                      'el' => 'elf', 'loa' => 'loaf', 'shel' => 'shelf'];
            if (isset($fWords[$root])) {
                return $fWords[$root];
            }
            return $root . 'f';  // Default case: add 'f'
        }
        
        // Default: remove trailing 's'
        if (substr($plural, -1) === 's') {
            return substr($plural, 0, -1);
        }
        
        return $plural;
    }


    /**
     * Check if a word is already in plural form
     * 
     * @param string $word Word to check
     * @return bool True if word is already plural
     */
    public static function isPlural($word)
    {
        $word = strtolower($word);
        
        // Handle special singular words that end with 's'
        if (in_array($word, self::$singularWithS)) {
            return false;
        }
        
        // Check for standard plural endings
        if (preg_match('/(s|es|ies|i|a|en)$/i', $word)) {
            // Common plural patterns
            if (substr($word, -3) === 'ies' || 
                substr($word, -2) === 'es' || 
                substr($word, -2) === 'en' || 
                substr($word, -1) === 'i' || 
                substr($word, -1) === 'a') {
                return true;
            }
            
            // Words ending with 's' but not 'ss', 'is', 'us'
            if (substr($word, -1) === 's' && 
                !in_array(substr($word, -2), ['ss', 'is', 'us'])) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Convert string to camelCase
     *
     * @param string $string Input string
     * @param bool $capitalizeFirstCharacter Capitalize first character
     * @return string
     */
    public static function toCamelCase($string, $capitalizeFirstCharacter = false)
    {
        $string = str_replace(['-', '_'], ' ', $string);
        $string = ucwords($string);
        $string = str_replace(' ', '', $string);
        
        if (!$capitalizeFirstCharacter) {
            $string = lcfirst($string);
        }
        
        return $string;
    }


    /**
     * Convert string to snake_case
     *
     * @param string $string Input string
     * @return string
     */
    public static function toSnakeCase($string)
    {
        $string = preg_replace('/\s+/u', '', ucwords($string));
        $string = preg_replace('/(.)(?=[A-Z])/u', '$1_', $string);
        return strtolower($string);
    }
    
    /**
     * Convert string to kebab-case
     *
     * @param string $string Input string
     * @return string
     */
    public static function toKebabCase($string)
    {
        return str_replace('_', '-', self::toSnakeCase($string));
    }

    /**
     * Convert string to pascal case (uppercase first letter)
     *
     * @param string $string Input string
     * @return string
     */
    public static function toPascalCase($string)
    {
        return self::toCamelCase($string, true);
    }
    
    /**
     * Get proper class name for a model based on naming conventions
     * 
     * @param string $name The input name
     * @param bool $forceSingular Force return in singular form
     * @return string Proper class name
     */
    public static function getProperClassName($name, $forceSingular = true)
    {
        if ($forceSingular) {
            if (self::isPlural($name)) {
                return self::toPascalCase(self::singularize($name));
            }
            return self::toPascalCase($name);
        } else {
            if (self::isPlural($name)) {
                return self::toPascalCase($name);
            }
            return self::toPascalCase(self::pluralize($name));
        }
    }
    
    /**
     * Get model table name from a model name
     * 
     * @param string $name Model name
     * @return string Table name with prefix placeholder
     */
    public static function getModelTableName($name)
    {
        $name = strtolower($name);
        if (self::isPlural($name)) {
            return '#PREFIX#' . $name;
        }
        return '#PREFIX#' . self::pluralize($name);
    }
    
    /**
     * Get the fully qualified table name with schema if needed
     * 
     * @param string $table Table name
     * @param string $schema Schema name
     * @param string $prefix Table prefix
     * @return string
     */
    public static function getFullTableName($table, $schema = null, $prefix = '')
    {
        // For PostgreSQL with schema defined, prepend the schema
        if ($schema !== null) {
            return str_replace(
                '#PREFIX#', $prefix, $schema . '.' . $table
            );
        }
        
        return str_replace(
            '#PREFIX#', $prefix, $table
        );
    }

    /**
     * Check if a string contains Greek vowels (which can have accents)
     * @param string $text Text to check
     * @return bool True if text contains Greek vowels
     */
    public static function containsGreekCharacters($text)
    {
        // Greek vowels that can have accents:
        // Α, Ε, Η, Ι, Ο, Υ, Ω (uppercase)
        // α, ε, η, ι, ο, υ, ω (lowercase)
        // Including their accented variants from Greek Extended block
        return preg_match('/[ΑΕΗΙΟΥΩαεηιουω\x{1F00}-\x{1F15}\x{1F18}-\x{1F1D}\x{1F20}-\x{1F45}\x{1F48}-\x{1F4D}\x{1F50}-\x{1F57}\x{1F59}\x{1F5B}\x{1F5D}\x{1F5F}-\x{1F7D}\x{1F80}-\x{1FAF}\x{1FB0}-\x{1FC4}\x{1FC6}-\x{1FD3}\x{1FD6}-\x{1FDB}\x{1FDD}-\x{1FEF}\x{1FF2}-\x{1FF4}\x{1FF6}-\x{1FFE}]/u', $text);
    }
}