<?php
/*
 *  Sidus/EAVModelBundle : EAV Data management in Symfony 3
 *  Copyright (C) 2015-2017 Vincent Chalnot
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Sidus\EAVModelBundle\DependencyInjection;

use Sidus\EAVModelBundle\Doctrine\Types\FamilyType;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\BadMethodCallException;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Parse configuration and creates attributes and families' services
 *
 * @author Vincent Chalnot <vincent@sidus.fr>
 */
class SidusEAVModelExtension extends Extension
{
    /** @var array */
    protected $globalConfig;

    /**
     * Generate automatically services for attributes and families from configuration
     *
     * @param array            $configs
     * @param ContainerBuilder $container
     *
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $this->globalConfig = $config;

        $container->setParameter('sidus_eav_model.entity.data.class', $config['data_class']);
        $container->setParameter('sidus_eav_model.entity.value.class', $config['value_class']);
        $container->setParameter('sidus_eav_model.form.collection_type', $config['collection_type']);
        $container->setParameter('sidus_eav_model.context.form_type', $config['context_form_type']);
        $container->setParameter('sidus_eav_model.context.default_context', $config['default_context']);
        $container->setParameter('sidus_eav_model.context.global_mask', $config['global_context_mask']);

        // Injecting custom doctrine type
        $doctrineTypes = $container->getParameter('doctrine.dbal.connection_factory.types');
        $doctrineTypes['sidus_family'] = ['class' => FamilyType::class, 'commented' => true];
        $container->setParameter('doctrine.dbal.connection_factory.types', $doctrineTypes);

        // Load services config
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config/services'));
        $loader->load('attribute_types.yml');
        $loader->load('configuration.yml');
        $loader->load('context.yml');
        $loader->load('doctrine.yml');
        $loader->load('entities.yml');
        $loader->load('events.yml');
        $loader->load('forms.yml');
        if ($config['serializer_enabled']) {
            // Only load normalizers if symfony serializer is loaded
            $loader->load('serializer.yml');
            $loader->load('normalizer.yml');
            $loader->load('denormalizer.yml');
        }
        $loader->load('param_converters.yml');
        $loader->load('twig.yml');
        $loader->load('validators.yml');

        // Add global attribute configuration to handler
        $attributeConfiguration = $container->getDefinition('sidus_eav_model.attribute.registry');
        $attributeConfiguration->addMethodCall('parseGlobalConfig', [$config['attributes']]);

        $this->createFamilyServices($config, $container);
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @throws \Exception
     */
    protected function createFamilyServices(array $config, ContainerBuilder $container)
    {
        // Automatically declare a service for each family configured
        foreach ((array) $config['families'] as $code => $familyConfiguration) {
            if (empty($familyConfiguration['data_class'])) {
                $familyConfiguration['data_class'] = $config['data_class'];
            }
            if (empty($familyConfiguration['value_class'])) {
                $familyConfiguration['value_class'] = $config['value_class'];
            }
            $this->addFamilyServiceDefinition($code, $familyConfiguration, $container);
        }
    }

    /**
     * @param string           $code
     * @param array            $familyConfiguration
     * @param ContainerBuilder $container
     *
     * @throws BadMethodCallException
     * @throws InvalidArgumentException
     */
    protected function addFamilyServiceDefinition($code, $familyConfiguration, ContainerBuilder $container)
    {
        $definition = new Definition(
            new Parameter('sidus_eav_model.family.class'),
            [
                $code,
                new Reference('sidus_eav_model.attribute.registry'),
                new Reference('sidus_eav_model.family.registry'),
                new Reference('sidus_eav_model.context.manager'),
                $familyConfiguration,
            ]
        );
        $definition->addMethodCall('setTranslator', [new Reference('translator')]);
        $definition->addTag('sidus.family');
        $container->setDefinition('sidus_eav_model.family.'.$code, $definition);
    }
}
