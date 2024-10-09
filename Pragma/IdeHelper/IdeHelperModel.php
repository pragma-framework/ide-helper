<?php

namespace Pragma\IdeHelper;

use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;
use Barryvdh\Reflection\DocBlock\Tag;
use PDO;
use phpDocumentor\Reflection\Types\ContextFactory;
use Pragma\DB\DB;
use Pragma\Helpers\TaskLock;
use Pragma\Orm\Relation;
use ReflectionClass;
use ReflectionException;

/**
 * Class for write ide helper from pragma model
 *
 * @package Pragma\IdeHelper
 */
class IdeHelperModel
{
    /**
     * String class  
     * @var string
     */
    public string $class;

    /**
     * Write or not the doc-blocks
     * @var bool
     */
    protected bool $write = true;

    /**
     * Chose if doc bloc is save in class file or mixin
     * @var bool
     */
    protected bool $write_mixin = false;

    /**
     * Remove the original phpdocs instead of appending
     * @var bool
     */
    protected bool $reset = false;

    /**
     * Refresh the properties/methods list, but keep the text
     * @var bool
     */
    protected bool $keep_text = false;

    /**
     * Constructor
     * @param string $class
     * @param array $options
     */
    public function __construct(string $class, array $options = [])
    {
        $this->class = $class;
        $this->write = $options['write'] ?? $this->write;
        $this->write_mixin = $options['write_mixin'] ?? $this->write_mixin;
        $this->reset = $options['reset'] ?? $this->reset;
        $this->keep_text = $options['keep_text'] ?? $this->keep_text;
    }

    /**
     * @return string
     * @throws ReflectionException
     */
    public function generateDocs(): string
    {
        $dbProperties = $this->getPropertiesFromTable($this->class);
        $reflection = new ReflectionClass($this->class);
        $namespace = $reflection->getNamespaceName();
        $classname = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();
        $keyword = $this->getClassKeyword($reflection);
        $interfaceNames = array_diff_key(
            $reflection->getInterfaceNames(),
            $reflection->getParentClass()->getInterfaceNames()
        );

        if ($this->reset) {
            $phpdoc = new DocBlock('', new Context($namespace));
            if ($this->keep_text) {
                $phpdoc->setText(
                    (new DocBlock($reflection, new Context($namespace)))->getText()
                );
            }
        } else {
            $phpdoc = new DocBlock($reflection, new Context($namespace));
        }

        foreach ($phpdoc->getTagsByName('mixin') as $tag) {
            if (str_starts_with($tag->getContent(), 'IdeHelper')) {
                $phpdoc->deleteTag($tag);
            }
        }

        if (!$phpdoc->getText()) {
            $phpdoc->setText($this->class);
        }

        $properties = [];
        foreach ($phpdoc->getTags() as $tag) {
            $name = $tag->getName();
            if ($name === 'property' || $name === 'property-read' || $name === 'property-write') {
                $properties[] = $tag->getVariableName();
            }
        }

        foreach ($dbProperties ?? [] as $name => $property) {
            $name = "\$$name";

            if (in_array($name, $properties)) {
                continue;
            }

            $tagLine = trim("@property {$property['type']} $name {$property['comment']}");
            $tag = Tag::createInstance($tagLine, $phpdoc);
            $phpdoc->appendTag($tag);
        }

        $relations = Relation::getAll($this->class);
        foreach ($relations as $r) {
            $type = '\\' . $r->get_class_to();
            $name = '$' . $r->get_name();
            $comment = '';
            switch ($r->get_type()) {
                case 'belongs_to':
                    $type .= '|null';
                    $comment = 'Relation belongs to';
                    break;
                case 'has_one':
                    $type .= '|null';
                    $comment = 'Relation has one';
                    break;
                case 'has_many_through':
                    $type .= '[]|null';
                    $comment = 'Relation has many through with ' . $r->get_sub_relation()['through'];
                    break;
                case 'has_many':
                    $type .= '[]|null';
                    $comment = 'Relation has many';
                    break;
            }
            $tagLine = trim("@property $type $name $comment");
            $tag = Tag::createInstance($tagLine, $phpdoc);
            $phpdoc->appendTag($tag);
        }

        $serializer = new DocBlockSerializer();
        $docComment = $serializer->getDocComment($phpdoc);
        
        if ($this->write_mixin) {
            $phpdocMixin = new DocBlock($reflection, new Context($namespace));
            // remove all mixin tags prefixed with IdeHelper
            foreach ($phpdocMixin->getTagsByName('mixin') as $tag) {
                if (str_starts_with($tag->getContent(), 'IdeHelper')) {
                    $phpdocMixin->deleteTag($tag);
                }
            }

            $mixinClassName = "IdeHelper$classname";
            $phpdocMixin->appendTag(Tag::createInstance("@mixin $mixinClassName", $phpdocMixin));
            $mixinDocComment = $serializer->getDocComment($phpdocMixin);
            // remove blank lines if there's no text
            if (!$phpdocMixin->getText()) {
                $mixinDocComment = preg_replace("/\s\*\s*\n/", '', $mixinDocComment);
            }

            foreach ($phpdoc->getTagsByName('mixin') as $tag) {
                if (str_starts_with($tag->getContent(), 'IdeHelper')) {
                    $phpdoc->deleteTag($tag);
                }
            }
            $docComment = $serializer->getDocComment($phpdoc);
        }

        if ($this->write) {
            $modelDocComment = $this->write_mixin ? $mixinDocComment : $docComment;
            $filename = $reflection->getFileName();
            $fileClass = fopen($filename, 'r+');
            $contents = fread($fileClass, filesize($filename));
            if ($originalDoc) {
                $contents = str_replace($originalDoc, $modelDocComment, $contents);
            } else {
                $replace = "$modelDocComment\n";
                $pos = strpos($contents, "final class $classname") ?: strpos($contents, "class $classname");
                if ($pos !== false) {
                    $contents = substr_replace($contents, $replace, $pos, 0);
                }
            }
            fseek($fileClass, 0);
            fwrite($fileClass, $contents);
            fclose($fileClass);
        }
        
        $classname = $this->write_mixin ? $mixinClassName : $classname;
        $output = "namespace $namespace{\n$docComment\n\t{$keyword}class $classname ";

        if (!$this->write_mixin) {
            $output .= "extends \Eloquent ";

            if ($interfaceNames) {
                $interfaces = implode(', \\', $interfaceNames);
                $output .= "implements \\$interfaces ";
            }
        }

        return $output . "{}\n}\n\n";
    }

    /**
     * Load the properties from the database table
     * @param $model
     * @return array|null
     */
    public function getPropertiesFromTable($model): ?array
    {
        $db = DB::getDB();

        $res = $db->query('SHOW FULL COLUMNS FROM ' . $model::build()->get_table());
        $columns = [];
        foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[] = array_change_key_case($column, CASE_LOWER);

        }

        if (!$columns) {
            return null;
        }

        $properties = [];
        foreach ($columns as $column) {
            $name = $column['field'];
            $pattern = "/(\w+)\((\d+)(?:,(\d+))?\)/";
            preg_match($pattern, $column['type'], $matches);
            if (isset($matches[2])) {
                $type = $matches[1];  // Type de données (char, int, timestamp, etc.)
                $typeLength = $matches[2];  // Longueur des données
            } else {
                $type = $column['type'];  // Type de données (char, int, timestamp, etc.)
            }
            $comment = $column['comment'];
            $nullable = strtolower($column['null']) === 'yes';
            $type = match ($type) {
                'string', 'varchar', 'char', 'text', 'date', 'time', 'guid', 'datetimetz', 'datetime', 'timestamp', 'longtext' => 'string',
                'integer', 'bigint', 'smallint', 'boolean', 'tinyint' => 'integer',
                'decimal', 'float' => 'float',
                default => 'mixed',
            };
            
            $properties[$name] = [
                'type' => $type,
                'read' => true,
                'write' => true,
                'comment' => (string)$comment,
            ];
            if ($nullable) {
                $properties[$name]['type'] .= '|null';
            }
            
        }
        return $properties;
    }

    /**
     * @param ReflectionClass $reflection
     * @return string
     */
    public function getClassKeyword(ReflectionClass $reflection): string
    {
        if ($reflection->isFinal()) {
            $keyword = 'final ';
        } elseif ($reflection->isAbstract()) {
            $keyword = 'abstract ';
        } else {
            $keyword = '';
        }

        return $keyword;
    }
}
