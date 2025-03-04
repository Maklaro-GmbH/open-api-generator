<?php

declare(strict_types=1);

namespace Protung\OpenApiGenerator\Tests\Integration;

use Doctrine\Common\Annotations\AnnotationReader;
use JMS\Serializer\Builder\DefaultDriverFactory;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\SerializerBuilder;
use Metadata\MetadataFactory;
use PHPUnit\Framework\TestCase;
use Protung\OpenApiGenerator\Describer;
use Protung\OpenApiGenerator\Generator;
use Protung\OpenApiGenerator\Model\ModelRegistry;
use Protung\OpenApiGenerator\Processor;
use Protung\OpenApiGenerator\Processor\Path;
use Protung\OpenApiGenerator\Tests\Integration\Fixtures\TestSchemaGeneration\Form\TestDictionaryType;
use Psl\Json;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryBuilder;
use Symfony\Component\Routing\Loader\XmlFileLoader;
use Symfony\Component\Validator\ValidatorBuilder;

final class GenerateSchemaTest extends TestCase
{
    private static function createGenerator(string $apiVersion): Generator
    {
        $routes = (new XmlFileLoader(new FileLocator(__DIR__ . '/Fixtures/TestSchemaGeneration/')))->load('routes.xml');

        $validator = (new ValidatorBuilder())->getValidator();

        $formFactory = (new FormFactoryBuilder())
            ->addExtensions(
                [new ValidatorExtension($validator)],
            )
            ->getFormFactory();

        $metadataDirs = [
            'Protung\OpenApiGenerator\Tests\Integration\Fixtures\TestSchemaGeneration\Model\JMS' => __DIR__ . '/Fixtures/TestSchemaGeneration/config/serializer',
        ];

        // @todo use EnumPropertiesDriver as the driver to simulate JMSSerializerBundle (@see https://github.com/schmittjoh/JMSSerializerBundle/pull/919)
        $jmsSerializerBuilder = SerializerBuilder::create();
        $jmsSerializerBuilder->addMetadataDirs($metadataDirs);
        $jmsSerializerBuilder->enableEnumSupport(true);

        $jmsSerializer = $jmsSerializerBuilder->build();

        $describerFormFactory = new Describer\Form\FormFactory($formFactory);

        $exampleDescriberJms        = new Describer\ExampleDescriber\JmsSerializerExampleDescriber($jmsSerializer);
        $exampleDescriberCollection = new Describer\ExampleDescriber\CollectionExampleDescriber($exampleDescriberJms);
        $exampleDescriber           = new Describer\ExampleDescriber\CompoundExampleDescriber(
            $exampleDescriberJms,
            $exampleDescriberCollection,
        );

        $formDescriber = new Describer\FormDescriber(
            new Describer\Form\SymfonyFormPropertyDescriber(
                new Describer\Form\PropertyDescriber\DictionaryPropertyDescriber($describerFormFactory, TestDictionaryType::class),
                new Describer\Form\PropertyDescriber\CollectionPropertyDescriber($describerFormFactory),
                new Describer\Form\PropertyDescriber\SymfonyBuiltInPropertyDescriber(),
            ),
            new Describer\Form\SymfonyValidatorRequirementsDescriber($validator),
        );

        $modelRegistry = new ModelRegistry();

        return new Generator(
            new Processor\InfoProcessor($apiVersion),
            new Processor\SecurityDefinitions(),
            new Processor\PathsProcessor(
                new Processor\Path\CompoundPathProcessor(
                    new Path\Symfony\PathProcessor(
                        $routes,
                        new Describer\OperationDescriber(
                            new Describer\InputDescriber(
                                new Describer\InputDescriber\SimpleInputDescriber(),
                                new Describer\InputDescriber\FormInputDescriber(
                                    $formDescriber,
                                    $describerFormFactory,
                                ),
                            ),
                            new Describer\OutputDescriber(
                                new Describer\ObjectDescriber(
                                    $modelRegistry,
                                    new Describer\ObjectDescriber\PHPBackedEnum(),
                                    new Describer\ObjectDescriber\JMSModel(
                                        new MetadataFactory(
                                            (new DefaultDriverFactory(new IdenticalPropertyNamingStrategy()))->createDriver(
                                                $metadataDirs,
                                                new AnnotationReader(),
                                            ),
                                        ),
                                        $apiVersion,
                                        false,
                                    ),
                                ),
                                $describerFormFactory,
                                $exampleDescriber,
                            ),
                        ),
                    ),
                ),
            ),
            new Processor\Definitions($modelRegistry),
        );
    }

    public function testSchemaGeneration(): void
    {
        $generator = self::createGenerator('0.0.1');

        $config = require __DIR__ . '/Fixtures/TestSchemaGeneration/definition.php';

        $openApiSpec = $generator->generate($config);

//        file_put_contents(
//            __DIR__ . '/Expected/testSchemaGeneration.json',
//            Json\encode($openApiSpec->getSerializableData(), true)
//        );

        self::assertTrue($openApiSpec->validate());
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/Expected/testSchemaGeneration.json',
            Json\encode($openApiSpec->getSerializableData()),
        );
    }
}
