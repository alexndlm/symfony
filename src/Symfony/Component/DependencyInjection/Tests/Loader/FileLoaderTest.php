<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader\FileLoader;
use Symfony\Component\DependencyInjection\Loader\IniFileLoader;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\BadClasses\MissingParent;
use Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\Foo;
use Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\FooInterface;
use Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\OtherDir\AnotherSub\DeeperBaz;
use Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\OtherDir\Baz;
use Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\Sub\Bar;
use Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\Sub\BarInterface;

class FileLoaderTest extends TestCase
{
    protected static $fixturesPath;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesPath = realpath(__DIR__.'/../');
    }

    public function testImportWithGlobPattern()
    {
        $container = new ContainerBuilder();
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath));

        $resolver = new LoaderResolver([
            new IniFileLoader($container, new FileLocator(self::$fixturesPath.'/ini')),
            new XmlFileLoader($container, new FileLocator(self::$fixturesPath.'/xml')),
            new PhpFileLoader($container, new FileLocator(self::$fixturesPath.'/php')),
            new YamlFileLoader($container, new FileLocator(self::$fixturesPath.'/yaml')),
        ]);

        $loader->setResolver($resolver);
        $loader->import('{F}ixtures/{xml,yaml}/services2.{yml,xml}');

        $actual = $container->getParameterBag()->all();
        $expected = [
            'a string',
            'foo' => 'bar',
            'values' => [
                0,
                'integer' => 4,
                100 => null,
                'true',
                true,
                false,
                'on',
                'off',
                'float' => 1.3,
                1000.3,
                'a string',
                ['foo', 'bar'],
            ],
            'mixedcase' => ['MixedCaseKey' => 'value'],
            'constant' => \PHP_EOL,
            'bar' => '%foo%',
            'escape' => '@escapeme',
            'foo_bar' => new Reference('foo_bar'),
        ];

        $this->assertEquals(array_keys($expected), array_keys($actual), '->load() imports and merges imported files');
    }

    public function testRegisterClasses()
    {
        $container = new ContainerBuilder();
        $container->setParameter('sub_dir', 'Sub');
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath.'/Fixtures'));
        $loader->autoRegisterAliasesForSinglyImplementedInterfaces = false;

        $loader->registerClasses(new Definition(), 'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\Sub\\', 'Prototype/%sub_dir%/*');
        $loader->registerClasses(new Definition(), 'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\Sub\\', 'Prototype/%sub_dir%/*'); // loading twice should not be an issue
        $loader->registerAliasesForSinglyImplementedInterfaces();

        $this->assertEquals(
            ['service_container', Bar::class],
            array_keys($container->getDefinitions())
        );
        $this->assertEquals(
            [
                PsrContainerInterface::class,
                ContainerInterface::class,
                BarInterface::class,
            ],
            array_keys($container->getAliases())
        );
    }

    public function testRegisterClassesWithExclude()
    {
        $container = new ContainerBuilder();
        $container->setParameter('other_dir', 'OtherDir');
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath.'/Fixtures'));

        $loader->registerClasses(
            new Definition(),
            'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\\',
            'Prototype/*',
            // load everything, except OtherDir/AnotherSub & Foo.php
            'Prototype/{%other_dir%/AnotherSub,Foo.php}'
        );

        $this->assertTrue($container->has(Bar::class));
        $this->assertTrue($container->has(Baz::class));
        $this->assertFalse($container->has(Foo::class));
        $this->assertFalse($container->has(DeeperBaz::class));

        $this->assertEquals(
            [
                PsrContainerInterface::class,
                ContainerInterface::class,
                BarInterface::class,
            ],
            array_keys($container->getAliases())
        );

        $loader->registerClasses(
            new Definition(),
            'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\\',
            'Prototype/*',
            'Prototype/NotExistingDir'
        );
    }

    public function testRegisterClassesWithExcludeAsArray()
    {
        $container = new ContainerBuilder();
        $container->setParameter('sub_dir', 'Sub');
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath.'/Fixtures'));
        $loader->registerClasses(
            new Definition(),
            'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\\',
            'Prototype/*', [
                'Prototype/%sub_dir%',
                'Prototype/OtherDir/AnotherSub/DeeperBaz.php',
            ]
        );

        $this->assertTrue($container->has(Foo::class));
        $this->assertTrue($container->has(Baz::class));
        $this->assertFalse($container->has(Bar::class));
        $this->assertFalse($container->has(DeeperBaz::class));
    }

    public function testNestedRegisterClasses()
    {
        $container = new ContainerBuilder();
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath.'/Fixtures'));

        $prototype = (new Definition())->setAutoconfigured(true);
        $loader->registerClasses($prototype, 'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\\', 'Prototype/*');

        $this->assertTrue($container->has(Bar::class));
        $this->assertTrue($container->has(Baz::class));
        $this->assertTrue($container->has(Foo::class));

        $this->assertEquals(
            [
                PsrContainerInterface::class,
                ContainerInterface::class,
                FooInterface::class,
            ],
            array_keys($container->getAliases())
        );

        $alias = $container->getAlias(FooInterface::class);
        $this->assertSame(Foo::class, (string) $alias);
        $this->assertFalse($alias->isPublic());
        $this->assertTrue($alias->isPrivate());

        if (\PHP_VERSION_ID >= 80000) {
            $this->assertEquals([FooInterface::class => (new ChildDefinition(''))->addTag('foo')], $container->getAutoconfiguredInstanceof());
        }
    }

    public function testMissingParentClass()
    {
        $container = new ContainerBuilder();
        $container->setParameter('bad_classes_dir', 'BadClasses');
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath.'/Fixtures'), 'test');

        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true),
            'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\BadClasses\\',
            'Prototype/%bad_classes_dir%/*'
        );

        $this->assertTrue($container->has(MissingParent::class));

        $this->assertMatchesRegularExpression(
            '{Class "?Symfony\\\\Component\\\\DependencyInjection\\\\Tests\\\\Fixtures\\\\Prototype\\\\BadClasses\\\\MissingClass"? not found}',
            $container->getDefinition(MissingParent::class)->getErrors()[0]
        );
    }

    public function testRegisterClassesWithBadPrefix()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Expected to find class "Symfony\\\Component\\\DependencyInjection\\\Tests\\\Fixtures\\\Prototype\\\Bar" in file ".+" while importing services from resource "Prototype\/Sub\/\*", but it was not found\! Check the namespace prefix used with the resource/');
        $container = new ContainerBuilder();
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath.'/Fixtures'));

        // the Sub is missing from namespace prefix
        $loader->registerClasses(new Definition(), 'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\\', 'Prototype/Sub/*');
    }

    public function testRegisterClassesWithIncompatibleExclude()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "exclude" pattern when importing classes for "Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\": make sure your "exclude" pattern (yaml/*) is a subset of the "resource" pattern (Prototype/*)');
        $container = new ContainerBuilder();
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath.'/Fixtures'));

        $loader->registerClasses(
            new Definition(),
            'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\\',
            'Prototype/*',
            'yaml/*'
        );
    }

    /**
     * @dataProvider excludeTrailingSlashConsistencyProvider
     */
    public function testExcludeTrailingSlashConsistency(string $exclude)
    {
        $container = new ContainerBuilder();
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath.'/Fixtures'));
        $loader->registerClasses(
            new Definition(),
            'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\\',
            'Prototype/*',
            $exclude
        );

        $this->assertTrue($container->has(Foo::class));
        $this->assertFalse($container->has(DeeperBaz::class));
    }

    public static function excludeTrailingSlashConsistencyProvider(): iterable
    {
        yield ['Prototype/OtherDir/AnotherSub/'];
        yield ['Prototype/OtherDir/AnotherSub'];
        yield ['Prototype/OtherDir/AnotherSub/*'];
        yield ['Prototype/*/AnotherSub'];
        yield ['Prototype/*/AnotherSub/'];
        yield ['Prototype/*/AnotherSub/*'];
        yield ['Prototype/OtherDir/AnotherSub/DeeperBaz.php'];
    }

    /**
     * @requires PHP 8
     *
     * @testWith ["prod", true]
     *           ["dev", true]
     *           ["bar", false]
     *           [null, true]
     */
    public function testRegisterClassesWithWhenEnv(?string $env, bool $expected)
    {
        $container = new ContainerBuilder();
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath.'/Fixtures'), $env);
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true),
            'Symfony\Component\DependencyInjection\Tests\Fixtures\Prototype\\',
            'Prototype/{Foo.php}'
        );

        $this->assertSame($expected, $container->has(Foo::class));
    }
}

class TestFileLoader extends FileLoader
{
    public $autoRegisterAliasesForSinglyImplementedInterfaces = true;

    public function load($resource, string $type = null)
    {
        return $resource;
    }

    public function supports($resource, string $type = null): bool
    {
        return false;
    }
}
