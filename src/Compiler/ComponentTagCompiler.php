<?php

namespace TomasVotruba\Bladestan\Compiler;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\ViewFinderInterface;
use InvalidArgumentException;
use ReflectionClass;

class ComponentTagCompiler
{
    /** The Blade compiler instance.  */
    protected \Illuminate\View\Compilers\BladeCompiler $blade;

    /**
     * The component class aliases.
     *
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * The component class namespaces.
     *
     * @var array<string, string>
     */
    protected array $namespaces = [];

    /**
     * The "bind:" attributes that have been compiled for the current component.
     *
     * @var array<string, bool>
     */
    protected array $boundAttributes = [];

    /**
     * Create a new component tag compiler.
     * @param array<string, string> $aliases
     * @param array<string, string> $namespaces
     */
    public function __construct(array $aliases = [], array $namespaces = [], ?BladeCompiler $blade = null)
    {
        $this->aliases = $aliases;
        $this->namespaces = $namespaces;

        $this->blade = $blade ?: new BladeCompiler(new Filesystem(), sys_get_temp_dir());
    }

    /**
     * Compile the component and slot tags within the given string.
     */
    public function compile(string $value): string
    {
        $value = $this->compileSlots($value);

        return $this->compileTags($value);
    }

    /**
     * Compile the tags within the given string.
     */
    public function compileTags(string $value): string
    {
        $value = $this->compileSelfClosingTags($value);
        $value = $this->compileOpeningTags($value);
        $value = $this->compileClosingTags($value);

        return $value;
    }

    /**
     * Get the component class for a given component alias.
     */
    public function componentClass(string $component): string
    {
        $viewFactory = Container::getInstance()->make(Factory::class);

        if (isset($this->aliases[$component])) {
            if (class_exists($alias = $this->aliases[$component])) {
                return $alias;
            }

            if ($viewFactory->exists($alias)) {
                return $alias;
            }

            throw new InvalidArgumentException(
                "Unable to locate class or view [{$alias}] for component [{$component}]."
            );
        }

        if ($class = $this->findClassByComponent($component)) {
            return $class;
        }

        if (class_exists($class = $this->guessClassName($component))) {
            return $class;
        }

        if (null !== ($guess = $this->guessAnonymousComponentUsingNamespaces($viewFactory, $component)) ||
            null !== ($guess = $this->guessAnonymousComponentUsingPaths($viewFactory, $component))) {
            return $guess;
        }

        if (Str::startsWith($component, 'mail::')) {
            return $component;
        }

        throw new InvalidArgumentException("Unable to locate a class or view for component [{$component}].");
    }

    /**
     * Find the class for the given component using the registered namespaces.
     */
    public function findClassByComponent(string $component): ?string
    {
        $segments = explode('::', $component);

        $prefix = $segments[0];

        if (! isset($this->namespaces[$prefix], $segments[1])) {
            return null;
        }

        if (class_exists($class = $this->namespaces[$prefix] . '\\' . $this->formatClassName($segments[1]))) {
            return $class;
        }

        return null;
    }

    /**
     * Guess the class name for the given component.
     */
    public function guessClassName(string $component): string
    {
        $namespace = Container::getInstance()
            ->make(Application::class)
            ->getNamespace();

        $class = $this->formatClassName($component);

        return $namespace . 'View\\Components\\' . $class;
    }

    /**
     * Format the class name for the given component.
     */
    public function formatClassName(string $component): string
    {
        $componentPieces = array_map(fn($componentPiece) => ucfirst(Str::camel($componentPiece)), explode('.', $component));

        return implode('\\', $componentPieces);
    }

    /**
     * Guess the view name for the given component.
     */
    public function guessViewName(string $name, string $prefix = 'components.'): string
    {
        if (! Str::endsWith($prefix, '.')) {
            $prefix .= '.';
        }

        $delimiter = ViewFinderInterface::HINT_PATH_DELIMITER;

        if (str_contains($name, $delimiter)) {
            return Str::replaceFirst($delimiter, $delimiter . $prefix, $name);
        }

        return $prefix . $name;
    }

    /**
     * Partition the data and extra attributes from the given array of attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, \Illuminate\Support\Collection<string, mixed>>
     */
    public function partitionDataAndAttributes(string $class, array $attributes): array
    {
        // If the class doesn't exist, we'll assume it is a class-less component and
        // return all of the attributes as both data and attributes since we have
        // now way to partition them. The user can exclude attributes manually.
        if (! class_exists($class)) {
            return [collect($attributes), collect($attributes)];
        }

        $constructor = (new ReflectionClass($class))->getConstructor();

        $parameterNames = $constructor
                    ? collect($constructor->getParameters())
                        ->map->getName()
                        ->all()
                    : [];

        return collect($attributes)->partition(fn($value, $key) => in_array(Str::camel($key), $parameterNames))->all();
    }

    /**
     * Compile the slot tags within the given string.
     */
    public function compileSlots(string $value): string
    {
        $pattern = "/
            <
                \s*
                x[\-\:]slot
                (?:\:(?<inlineName>\w+(?:-\w+)*))?
                (?:\s+(:?)name=(?<name>(\"[^\"]+\"|\\\'[^\\\']+\\\'|[^\s>]+)))?
                (?<attributes>
                    (?:
                        \s+
                        (?:
                            (?:
                                @(?:class)(\( (?: (?>[^()]+) | (?-1) )* \))
                            )
                            |
                            (?:
                                @(?:style)(\( (?: (?>[^()]+) | (?-1) )* \))
                            )
                            |
                            (?:
                                \{\{\s*\\\$attributes(?:[^}]+?)?\s*\}\}
                            )
                            |
                            (?:
                                [\w\-:.@]+
                                (
                                    =
                                    (?:
                                        \\\"[^\\\"]*\\\"
                                        |
                                        \'[^\']*\'
                                        |
                                        [^\'\\\"=<>]+
                                    )
                                )?
                            )
                        )
                    )*
                    \s*
                )
                (?<![\/=\-])
            >
        /x";

        $value = preg_replace_callback($pattern, function ($matches) {
            $name = $this->stripQuotes($matches['inlineName'] ?: $matches['name']);

            if (Str::contains($name, '-') && ! empty($matches['inlineName'])) {
                $name = Str::camel($name);
            }

            if ($matches[2] !== ':') {
                $name = "'{$name}'";
            }

            $this->boundAttributes = [];

            $attributes = $this->getAttributesFromAttributeString($matches['attributes']);

            return " @slot({$name}, null, [" . $this->attributesToString($attributes) . ']) ';
        }, $value);

        return preg_replace('/<\/\s*x[\-\:]slot[^>]*>/', ' @endslot', $value);
    }

    /**
     * Strip any quotes from the given string.
     */
    public function stripQuotes(string $value): string
    {
        return Str::startsWith($value, ['"', '\''])
                    ? substr($value, 1, -1)
                    : $value;
    }

    /**
     * Compile the opening tags within the given string.
     */
    protected function compileOpeningTags(string $value): string
    {
        $pattern = "/
            <
                \s*
                x[-\:]([\w\-\:\.]*)
                (?<attributes>
                    (?:
                        \s+
                        (?:
                            (?:
                                @(?:class)(\( (?: (?>[^()]+) | (?-1) )* \))
                            )
                            |
                            (?:
                                @(?:style)(\( (?: (?>[^()]+) | (?-1) )* \))
                            )
                            |
                            (?:
                                \{\{\s*\\\$attributes(?:[^}]+?)?\s*\}\}
                            )
                            |
                            (?:
                                (\:\\\$)(\w+)
                            )
                            |
                            (?:
                                [\w\-:.@]+
                                (
                                    =
                                    (?:
                                        \\\"[^\\\"]*\\\"
                                        |
                                        \'[^\']*\'
                                        |
                                        [^\'\\\"=<>]+
                                    )
                                )?
                            )
                        )
                    )*
                    \s*
                )
                (?<![\/=\-])
            >
        /x";

        return preg_replace_callback($pattern, function (array $matches) {
            $this->boundAttributes = [];

            $attributes = $this->getAttributesFromAttributeString($matches['attributes']);

            return $this->componentString($matches[1], $attributes);
        }, $value);
    }

    /**
     * Compile the self-closing tags within the given string.
     */
    protected function compileSelfClosingTags(string $value): string
    {
        $pattern = "/
            <
                \s*
                x[-\:]([\w\-\:\.]*)
                \s*
                (?<attributes>
                    (?:
                        \s+
                        (?:
                            (?:
                                @(?:class)(\( (?: (?>[^()]+) | (?-1) )* \))
                            )
                            |
                            (?:
                                @(?:style)(\( (?: (?>[^()]+) | (?-1) )* \))
                            )
                            |
                            (?:
                                \{\{\s*\\\$attributes(?:[^}]+?)?\s*\}\}
                            )
                            |
                            (?:
                                (\:\\\$)(\w+)
                            )
                            |
                            (?:
                                [\w\-:.@]+
                                (
                                    =
                                    (?:
                                        \\\"[^\\\"]*\\\"
                                        |
                                        \'[^\']*\'
                                        |
                                        [^\'\\\"=<>]+
                                    )
                                )?
                            )
                        )
                    )*
                    \s*
                )
            \/>
        /x";

        return preg_replace_callback($pattern, function (array $matches) {
            $this->boundAttributes = [];

            $attributes = $this->getAttributesFromAttributeString($matches['attributes']);

            return $this->componentString($matches[1], $attributes) . "\n@endComponentClass##END-COMPONENT-CLASS##";
        }, $value);
    }

    /**
     * Compile the Blade component string for the given component and attributes.
     */
    protected function componentString(string $component, array $attributes): string
    {
        $class = $this->componentClass($component);

        // If the component doesn't exist as a class, we'll assume it's a class-less
        // component and pass the component as a view parameter to the data so it
        // can be accessed within the component and we can render out the view.
        if (! class_exists($class)) {
            $view = Str::startsWith($component, 'mail::')
                ? "\$__env->getContainer()->make(Illuminate\\View\\Factory::class)->make('{$component}')"
                : "'{$class}'";

            $parameters = [
                'view' => $view,
                'data' => '[' . $this->attributesToString($attributes, $escapeBound = false) . ']',
            ];

            $class = AnonymousComponent::class;
        } else {
            [$data, $attributes] = $this->partitionDataAndAttributes($class, $attributes);

            $data = $data->mapWithKeys(fn ($value, $key) => [
                Str::camel($key) => $value,
            ]);
            $parameters = [
                ...$data,
                '_data' => '[' . $this->attributesToString($attributes->all(), $escapeBound = false) . ']',
            ];
        }

        $attrString = $this->attributesToString($parameters, $escapeBound = false);

        return "<?php {$class}::resolve([{$attrString}]); ?>";
    }

    /**
     * Attempt to find an anonymous component using the registered anonymous component paths.
     */
    protected function guessAnonymousComponentUsingPaths(Factory $viewFactory, string $component): ?string
    {
        $delimiter = ViewFinderInterface::HINT_PATH_DELIMITER;

        foreach ($this->blade->getAnonymousComponentPaths() as $path) {
            try {
                if (str_contains($component, $delimiter) &&
                    ! str_starts_with($component, $path['prefix'] . $delimiter)) {
                    continue;
                }

                $formattedComponent = str_starts_with($component, $path['prefix'] . $delimiter)
                        ? Str::after($component, $delimiter)
                        : $component;

                if (null !== ($guess = match (true) {
                    $viewFactory->exists($guess = $path['prefixHash'] . $delimiter . $formattedComponent) => $guess,
                    $viewFactory->exists(
                        $guess = $path['prefixHash'] . $delimiter . $formattedComponent . '.index'
                    ) => $guess,
                    default => null,
                })) {
                    return $guess;
                }
            } catch (InvalidArgumentException $e) {
                //
            }
        }
        return null;
    }

    /**
     * Attempt to find an anonymous component using the registered anonymous component namespaces.
     */
    protected function guessAnonymousComponentUsingNamespaces(Factory $viewFactory, string $component): ?string
    {
        return collect($this->blade->getAnonymousComponentNamespaces())
            ->filter(fn($directory, $prefix) => Str::startsWith($component, $prefix . '::'))
            ->prepend('components', $component)
            ->reduce(function ($carry, $directory, $prefix) use ($component, $viewFactory) {
                if ($carry !== null) {
                    return $carry;
                }

                $componentName = Str::after($component, $prefix . '::');

                if ($viewFactory->exists($view = $this->guessViewName($componentName, $directory))) {
                    return $view;
                }

                if ($viewFactory->exists($view = $this->guessViewName($componentName, $directory) . '.index')) {
                    return $view;
                }

                return null;
            });
    }

    /**
     * Compile the closing tags within the given string.
     */
    protected function compileClosingTags(string $value): string
    {
        return preg_replace("/<\/\s*x[-\:][\w\-\:\.]*\s*>/", ' @endComponentClass##END-COMPONENT-CLASS##', $value);
    }

    /**
     * Get an array of attributes from the given attribute string.
     *
     * @return array<string, mixed>
     */
    protected function getAttributesFromAttributeString(string $attributeString): array
    {
        $attributeString = $this->parseShortAttributeSyntax($attributeString);
        $attributeString = $this->parseAttributeBag($attributeString);
        $attributeString = $this->parseComponentTagClassStatements($attributeString);
        $attributeString = $this->parseComponentTagStyleStatements($attributeString);
        $attributeString = $this->parseBindAttributes($attributeString);

        $pattern = '/
            (?<attribute>[\w\-:.@]+)
            (
                =
                (?<value>
                    (
                        \"[^\"]+\"
                        |
                        \\\'[^\\\']+\\\'
                        |
                        [^\s>]+
                    )
                )
            )?
        /x';

        if (! preg_match_all($pattern, $attributeString, $matches, PREG_SET_ORDER)) {
            return [];
        }

        return collect($matches)->mapWithKeys(function ($match) {
            $attribute = $match['attribute'];
            $value = $match['value'] ?? null;

            if ($value === null) {
                $value = 'true';

                $attribute = Str::start($attribute, 'bind:');
            }

            $value = $this->stripQuotes($value);

            if (str_starts_with($attribute, 'bind:')) {
                $attribute = Str::after($attribute, 'bind:');

                $this->boundAttributes[$attribute] = true;
            } else {
                $value = "'" . $this->compileAttributeEchos($value) . "'";
            }

            if (str_starts_with($attribute, '::')) {
                $attribute = substr($attribute, 1);
            }

            return [
                $attribute => $value,
            ];
        })->toArray();
    }

    /**
     * Parses a short attribute syntax like :$foo into a fully-qualified syntax like :foo="$foo".
     */
    protected function parseShortAttributeSyntax(string $value): string
    {
        $pattern = "/\s\:\\\$(\w+)/x";

        return preg_replace_callback($pattern, fn(array $matches) => " :{$matches[1]}=\"\${$matches[1]}\"", $value);
    }

    /**
     * Parse the attribute bag in a given attribute string into its fully-qualified syntax.
     */
    protected function parseAttributeBag(string $attributeString): string
    {
        $pattern = "/
            (?:^|\s+)                                        # start of the string or whitespace between attributes
            \{\{\s*(\\\$attributes(?:[^}]+?(?<!\s))?)\s*\}\} # exact match of attributes variable being echoed
        /x";

        return preg_replace($pattern, ' :attributes="$1"', $attributeString);
    }

    /**
     * Parse @class statements in a given attribute string into their fully-qualified syntax.
     */
    protected function parseComponentTagClassStatements(string $attributeString): string
    {
        return preg_replace_callback(
            '/@(class)(\( ( (?>[^()]+) | (?2) )* \))/x',
            function ($match) {
                if ($match[1] === 'class') {
                    $match[2] = str_replace('"', "'", $match[2]);

                    return ":class=\"\Illuminate\Support\Arr::toCssClasses{$match[2]}\"";
                }

                return $match[0];
            },
            $attributeString
        );
    }

    /**
     * Parse @style statements in a given attribute string into their fully-qualified syntax.
     */
    protected function parseComponentTagStyleStatements(string $attributeString): string
    {
        return preg_replace_callback(
            '/@(style)(\( ( (?>[^()]+) | (?2) )* \))/x',
            function ($match) {
                if ($match[1] === 'style') {
                    $match[2] = str_replace('"', "'", $match[2]);

                    return ":style=\"\Illuminate\Support\Arr::toCssStyles{$match[2]}\"";
                }

                return $match[0];
            },
            $attributeString
        );
    }

    /**
     * Parse the "bind" attributes in a given attribute string into their fully-qualified syntax.
     */
    protected function parseBindAttributes(string $attributeString): string
    {
        $pattern = "/
            (?:^|\s+)     # start of the string or whitespace between attributes
            :(?!:)        # attribute needs to start with a single colon
            ([\w\-:.@]+)  # match the actual attribute name
            =             # only match attributes that have a value
        /xm";

        return preg_replace($pattern, ' bind:$1=', $attributeString);
    }

    /**
     * Compile any Blade echo statements that are present in the attribute string.
     *
     * These echo statements need to be converted to string concatenation statements.
     */
    protected function compileAttributeEchos(string $attributeString): string
    {
        $value = $this->blade->compileEchos($attributeString);

        $value = $this->escapeSingleQuotesOutsideOfPhpBlocks($value);

        $value = str_replace('<?php echo ', '\'.', $value);
        $value = str_replace('; ?>', '.\'', $value);

        return $value;
    }

    /**
     * Escape the single quotes in the given string that are outside of PHP blocks.
     */
    protected function escapeSingleQuotesOutsideOfPhpBlocks(string $value): string
    {
        return collect(token_get_all($value))
            ->map(function ($token) {
                if (! is_array($token)) {
                    return $token;
                }

                return $token[0] === T_INLINE_HTML
                            ? str_replace("'", "\\'", $token[1])
                            : $token[1];
            })->implode('');
    }

    /**
     * Convert an array of attributes to a string.
     */
    protected function attributesToString(array $attributes, bool $escapeBound = true): string
    {
        return collect($attributes)
            ->map(fn(string $value, string $attribute) => $escapeBound && isset($this->boundAttributes[$attribute]) && $value !== 'true' && ! is_numeric(
                $value
            )
                        ? "'{$attribute}' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute({$value})"
                        : "'{$attribute}' => {$value}")
            ->implode(',');
    }
}
