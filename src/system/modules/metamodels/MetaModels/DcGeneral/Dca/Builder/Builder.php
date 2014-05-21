<?php
/**
 * PHP version 5
 * @package    generalDriver
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Stefan Heimes <stefan_heimes@hotmail.com>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @copyright  The MetaModels team.
 * @license    LGPL.
 * @filesource
 */

namespace MetaModels\DcGeneral\Dca\Builder;

use ContaoCommunityAlliance\Contao\Bindings\ContaoEvents;
use ContaoCommunityAlliance\Contao\Bindings\Events\Image\ResizeImageEvent;
use ContaoCommunityAlliance\Contao\Bindings\Events\System\LoadLanguageFileEvent;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\GetOperationButtonEvent;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\DefaultModelRelationshipDefinition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\ModelRelationshipDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View\CutCommand;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ModelRelationship\FilterBuilder;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ModelRelationship\ParentChildCondition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ModelRelationship\ParentChildConditionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ModelRelationship\RootCondition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ModelRelationship\RootConditionInterface;
use ContaoCommunityAlliance\DcGeneral\DcGeneralEvents;
use ContaoCommunityAlliance\Translator\StaticTranslator;
use ContaoCommunityAlliance\Translator\TranslatorChain;
use ContaoCommunityAlliance\DcGeneral\Contao\DataDefinition\Definition\Contao2BackendViewDefinition;
use ContaoCommunityAlliance\DcGeneral\Contao\DataDefinition\Definition\Contao2BackendViewDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\Contao\Dca\ContaoDataProviderInformation;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\DataProviderDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\DefaultBasicDefinition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\DefaultDataProviderDefinition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\DefaultPalettesDefinition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\DefaultPropertiesDefinition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\Properties\DefaultProperty;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\PalettesDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\PropertiesDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View\Command;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View\CommandCollectionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View\DefaultModelFormatterConfig;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View\ListingConfigInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\Condition\Palette\DefaultPaletteCondition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\Condition\Property\BooleanCondition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\Condition\Property\PropertyConditionChain;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\Condition\Property\PropertyTrueCondition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\Condition\Property\PropertyValueCondition;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\Legend;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\LegendInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\Palette;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\Property;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Factory\Event\BuildDataDefinitionEvent;
use ContaoCommunityAlliance\DcGeneral\Factory\Event\PopulateEnvironmentEvent;
use MetaModels\BackendIntegration\InputScreen\IInputScreen;
use MetaModels\BackendIntegration\ViewCombinations;
use MetaModels\DcGeneral\DataDefinition\Definition\MetaModelDefinition;
use MetaModels\DcGeneral\DataDefinition\IMetaModelDataDefinition;
use MetaModels\DcGeneral\DataDefinition\Palette\Condition\Property\IsVariantAttribute;
use MetaModels\DcGeneral\Events\MetaModel\RenderItem;
use MetaModels\Events\BuildAttributeEvent;
use MetaModels\Events\PopulateAttributeEvent;
use MetaModels\Factory;
use MetaModels\Helper\ToolboxFile;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Build the container config from MetaModels information.
 */
class Builder
{
	const PRIORITY = 50;

	/**
	 * The translator instance this builder adds values to.
	 *
	 * @var StaticTranslator
	 */
	protected $translator;

	/**
	 * The event dispatcher currently in use.
	 *
	 * @var EventDispatcherInterface
	 */
	protected $dispatcher;

	/**
	 * Create a new instance and instantiate the translator.
	 */
	public function __construct()
	{
		$this->translator = new StaticTranslator();
	}

	/**
	 * Map all translation values from the given array to the given destination domain using the optional given base key.
	 *
	 * @param array  $array   The array holding the translation values.
	 *
	 * @param string $domain  The target domain.
	 *
	 * @param string $baseKey The base key to prepend the values of the array with.
	 *
	 * @return void
	 */
	protected function mapTranslations($array, $domain, $baseKey = '')
	{
		foreach ($array as $key => $value)
		{
			$newKey = ($baseKey ? $baseKey . '.' : '') . $key;
			if (is_array($value))
			{
				$this->mapTranslations($value, $domain, $newKey);
			}
			else
			{
				$this->translator->setValue($newKey, $value, $domain);
			}
		}
	}

	/**
	 * Handle a populate environment event for MetaModels.
	 *
	 * @param PopulateEnvironmentEvent $event The event.
	 *
	 * @return void
	 */
	public function populate(PopulateEnvironmentEvent $event)
	{
		$container = $event->getEnvironment()->getDataDefinition();

		if (!($container instanceof IMetaModelDataDefinition))
		{
			return;
		}

		$this->dispatcher = $event->getDispatcher();

		$translator = $event->getEnvironment()->getTranslator();

		if (!$translator instanceof TranslatorChain)
		{
			$translatorChain = new TranslatorChain();
			$translatorChain->add($translator);
			$event->getEnvironment()->setTranslator($translatorChain);
		}
		else
		{
			$translatorChain = $translator;
		}

		// Map the tl_metamodel_item domain over to this domain.
		$this->dispatcher->dispatch(
			ContaoEvents::SYSTEM_LOAD_LANGUAGE_FILE,
			new LoadLanguageFileEvent('tl_metamodel_item')
		);

		$this->mapTranslations(
			$GLOBALS['TL_LANG']['tl_metamodel_item'],
			$event->getEnvironment()->getDataDefinition()->getName()
		);

		$translatorChain->add($this->translator);

		$metaModel   = $this->getMetaModel($container);
		$environment = $event->getEnvironment();
		foreach ($metaModel->getAttributes() as $attribute)
		{
			$event = new PopulateAttributeEvent($metaModel, $attribute, $environment);
			// Trigger BuildAttribute Event.
			$this->dispatcher->dispatch($event::NAME, $event);
		}

		$this->dispatcher->addListener(
			sprintf(
				'%s[%s][%s]',
				GetOperationButtonEvent::NAME,
				$metaModel->getTableName(),
				'createvariant'
			),
			'MetaModels\DcGeneral\Events\MetaModel\CreateVariantButton::createButton'
		);

		$this->dispatcher->addListener(
			sprintf(
				'%s[%s][%s]',
				DcGeneralEvents::ACTION,
				$metaModel->getTableName(),
				'createvariant'
			),
			'MetaModels\DcGeneral\Events\MetaModel\CreateVariantButton::handleCreateVariantAction'
		);
	}

	/**
	 * Return the input screen details.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @return IInputScreen
	 */
	protected function getInputScreenDetails(IMetaModelDataDefinition $container)
	{
		return ViewCombinations::getInputScreenDetails($container->getName());
	}

	/**
	 * Retrieve the MetaModel for the data container.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @return \MetaModels\IMetaModel|null
	 */
	protected function getMetaModel(IMetaModelDataDefinition $container)
	{
		return Factory::byTableName($container->getName());
	}

	/**
	 * Retrieve the data provider definition.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @return DataProviderDefinitionInterface|DefaultDataProviderDefinition
	 */
	protected function getDataProviderDefinition(IMetaModelDataDefinition $container)
	{
		// Parse data provider.
		if ($container->hasDataProviderDefinition())
		{
			return $container->getDataProviderDefinition();
		}

		$config = new DefaultDataProviderDefinition();
		$container->setDataProviderDefinition($config);
		return $config;
	}

	/**
	 * Handle a build data definition event for MetaModels.
	 *
	 * @param BuildDataDefinitionEvent $event The event.
	 *
	 * @return void
	 */
	public function build(BuildDataDefinitionEvent $event)
	{
		$this->dispatcher = $event->getDispatcher();

		$container = $event->getContainer();

		if (!($container instanceof IMetaModelDataDefinition))
		{
			return;
		}

		$this->parseMetaModelDefinition($container);
		$this->parseProperties($container);
		$this->parseBasicDefinition($container);
		$this->parseDataProvider($container);
		$this->parseBackendView($container);

		$this->parsePalettes($container);

		// Attach renderer to event.
		RenderItem::register($event->getDispatcher());
	}

	/**
	 * Parse the basic configuration and populate the definition.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @return void
	 */
	protected function parseMetaModelDefinition(IMetaModelDataDefinition $container)
	{
		if ($container->hasMetaModelDefinition())
		{
			$definition = $container->getMetaModelDefinition();
		}
		else
		{
			$definition = new MetaModelDefinition();
			$container->setMetaModelDefinition($definition);
		}

		if (!$definition->hasActiveRenderSetting())
		{
			$definition->setActiveRenderSetting(ViewCombinations::getRenderSetting($container->getName()));
		}

		if (!$definition->hasActiveInputScreen())
		{
			$definition->setActiveInputScreen(ViewCombinations::getInputScreen($container->getName()));
		}
	}

	/**
	 * Parse the basic configuration and populate the definition.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @return void
	 */
	protected function parseBasicDefinition(IMetaModelDataDefinition $container)
	{
		if ($container->hasBasicDefinition())
		{
			$config = $container->getBasicDefinition();
		}
		else
		{
			$config = new DefaultBasicDefinition();
			$container->setBasicDefinition($config);
		}

		$config->setDataProvider($container->getName());

		$inputScreen = $this->getInputScreenDetails($container);

		switch ($inputScreen->getMode())
		{
			case 0:
			case 1:
			case 2:
			case 3:
				// Flat mode.
				// 0 Records are not sorted.
				// 1 Records are sorted by a fixed field.
				// 2 Records are sorted by a switchable field.
				// 3 Records are sorted by the parent table.
				$config->setMode(BasicDefinitionInterface::MODE_FLAT);
				break;
			case 4:
				// Displays the child records of a parent record (see style sheets module).
				$config->setMode(BasicDefinitionInterface::MODE_PARENTEDLIST);
				break;
			case 5:
			case 6:
				// Hierarchical mode.
				// 5 Records are displayed as tree (see site structure).
				// 6 Displays the child records within a tree structure (see articles module).
				$config->setMode(BasicDefinitionInterface::MODE_HIERARCHICAL);

				break;
			default:
		}

		if (($value = $inputScreen->isClosed()) !== null)
		{
			$config->setClosed((bool)$value);
		}

		$this->calculateConditions($container);
	}

	/**
	 * Parse the correct conditions.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @return void
	 */
	protected function calculateConditions(IMetaModelDataDefinition $container)
	{
		if ($container->hasDefinition(ModelRelationshipDefinitionInterface::NAME))
		{
			$definition = $container->getDefinition(ModelRelationshipDefinitionInterface::NAME);
		}
		else
		{
			$definition = new DefaultModelRelationshipDefinition();

			$container->setDefinition(ModelRelationshipDefinitionInterface::NAME, $definition);
		}

		if ($this->getMetaModel($container)->hasVariants())
		{
			$this->calculateConditionsWithVariants($container, $definition);
		}
		else
		{
			$this->calculateConditionsWithoutVariants($container, $definition);
		}
	}

	/**
	 * Parse the correct conditions for a MetaModel with variant support.
	 *
	 * @param IMetaModelDataDefinition             $container  The data container.
	 *
	 * @param ModelRelationshipDefinitionInterface $definition The relationship container.
	 *
	 * @return RootConditionInterface
	 */
	protected function getRootCondition($container, $definition)
	{
		$rootProvider = $container->getName();

		if (($relationship = $definition->getRootCondition()) === null)
		{
			$relationship = new RootCondition();
			$relationship
				->setSourceName($rootProvider);
			$definition->setRootCondition($relationship);
		}

		return $relationship;
	}

	/**
	 * Parse the correct conditions for a MetaModel with variant support.
	 *
	 * @param IMetaModelDataDefinition             $container  The data container.
	 *
	 * @param ModelRelationshipDefinitionInterface $definition The relationship container.
	 *
	 * @return void
	 */
	protected function addHierarchicalConditions(IMetaModelDataDefinition $container, $definition)
	{
		// Not hierarchical? Get out.
		if ($container->getBasicDefinition()->getMode() !== BasicDefinitionInterface::MODE_HIERARCHICAL)
		{
			return;
		}

		$relationship = $this->getRootCondition($container, $definition);

		if (!$relationship->getSetters())
		{
			$relationship
				->setSetters(array(array('property' => 'pid', 'value' => '0')));
		}

		$builder = FilterBuilder::fromArrayForRoot((array)$relationship->getFilterArray())->getFilter();

		$builder->andPropertyEquals('pid', 0);

		$relationship
			->setFilterArray($builder->getAllAsArray());

		$setter  = array(array('to_field' => 'pid', 'from_field' => 'id'));
		$inverse = array();

		/** @var ParentChildConditionInterface $relationship */
		$relationship = $definition->getChildCondition($container->getName(), $container->getName());
		if ($relationship === null)
		{
			$relationship = new ParentChildCondition();
			$relationship
				->setSourceName($container->getName())
				->setDestinationName($container->getName());
			$definition->addChildCondition($relationship);
		}
		else
		{
			$setter  = array_merge_recursive($setter, $relationship->getSetters());
			$inverse = array_merge_recursive($inverse, $relationship->getInverseFilterArray());
		}

		// For tl_ prefix, the only unique target can be the id?
		// maybe load parent dc and scan for unique in config then.
		$relationship
			->setFilterArray(
				FilterBuilder::fromArray($relationship->getFilterArray())
					->getFilter()
					->andRemotePropertyEquals('pid', 'id')
					->getAllAsArray()
			)
			->setSetters($setter)
			->setInverseFilterArray($inverse);
	}

	/**
	 * Parse the correct conditions for a MetaModel with variant support.
	 *
	 * @param IMetaModelDataDefinition             $container  The data container.
	 *
	 * @param ModelRelationshipDefinitionInterface $definition The relationship container.
	 *
	 * @return void
	 */
	protected function addParentCondition(IMetaModelDataDefinition $container, $definition)
	{
		$inputScreen = $this->getInputScreenDetails($container);

		if ($this->getInputScreenDetails($container)->isStandalone())
		{
			return;
		}

		$setter  = array(array('to_field' => 'pid', 'from_field' => 'id'));
		$inverse = array();

		/** @var ParentChildConditionInterface $relationship */
		$relationship = $definition->getChildCondition($inputScreen->getParentTable(), $container->getName());
		if (!$relationship instanceof ParentChildConditionInterface)
		{
			$relationship = new ParentChildCondition();
			$relationship
				->setSourceName($inputScreen->getParentTable())
				->setDestinationName($container->getName());
			$definition->addChildCondition($relationship);
		}
		else
		{
			$setter  = array_merge_recursive($setter, $relationship->getSetters());
			$inverse = array_merge_recursive($inverse, $relationship->getInverseFilterArray());
		}

		// For tl_ prefix, the only unique target can be the id?
		// maybe load parent dc and scan for unique in config then.
		$relationship
			->setFilterArray(
				FilterBuilder::fromArray($relationship->getFilterArray())
					->getFilter()
					->andRemotePropertyEquals('pid', 'id')
					->getAllAsArray()
			)
			->setSetters($setter)
			->setInverseFilterArray($inverse);
	}

	/**
	 * Parse the correct conditions for a MetaModel with variant support.
	 *
	 * @param IMetaModelDataDefinition             $container  The data container.
	 *
	 * @param ModelRelationshipDefinitionInterface $definition The relationship container.
	 *
	 * @return bool
	 */
	protected function calculateConditionsWithVariants(IMetaModelDataDefinition $container, $definition)
	{
		// Basic conditions.
		$this->addHierarchicalConditions($container, $definition);
		$this->addParentCondition($container, $definition);

		// Conditions for metamodels variants.
		$relationship = $this->getRootCondition($container, $definition);
		$relationship->setSetters(array_merge_recursive(
			array(array('property' => 'varbase', 'value' => '1')),
			$relationship->getSetters()
		));

		$builder = FilterBuilder::fromArrayForRoot((array)$relationship->getFilterArray())->getFilter();

		$builder->andPropertyEquals('varbase', 1);

		$relationship->setFilterArray($builder->getAllAsArray());

		$setter  = array(
			array('to_field' => 'varbase', 'value' => '0'),
			array('to_field' => 'vargroup', 'from_field' => 'vargroup')
		);
		$inverse = array();

		/** @var ParentChildConditionInterface $relationship */
		$relationship = $definition->getChildCondition($container->getName(), $container->getName());

		if ($relationship === null)
		{
			$relationship = new ParentChildCondition();
			$relationship
				->setSourceName($container->getName())
				->setDestinationName($container->getName());
			$definition->addChildCondition($relationship);
		}
		else
		{
			$setter  = array_merge_recursive($setter, $relationship->getSetters());
			$inverse = array_merge_recursive($inverse, $relationship->getInverseFilterArray());
		}

		$relationship
			->setFilterArray(
				FilterBuilder::fromArray($relationship->getFilterArray())
					->getFilter()
					->getBuilder()->encapsulateOr()
						->andRemotePropertyEquals('vargroup', 'vargroup')
						->andRemotePropertyEquals('vargroup', 'id')
						->andRemotePropertyEquals('varbase', 0, true)
					->getAllAsArray()
			)
			->setSetters($setter)
			->setInverseFilterArray($inverse);
	}

	/**
	 * Parse the correct conditions for a MetaModel with variant support.
	 *
	 * @param IMetaModelDataDefinition             $container  The data container.
	 *
	 * @param ModelRelationshipDefinitionInterface $definition The relationship container.
	 *
	 * @return void
	 */
	protected function calculateConditionsWithoutVariants(IMetaModelDataDefinition $container, $definition)
	{
		$inputScreen = $this->getInputScreenDetails($container);
		if (!$inputScreen->isStandalone())
		{
			if ($container->getBasicDefinition()->getMode() == BasicDefinitionInterface::MODE_HIERARCHICAL)
			{
				// FIXME: if parent table is not the same table, we are screwed here.
				throw new \RuntimeException('Hierarchical mode with parent table is not supported yet.');
			}
		}

		$this->addHierarchicalConditions($container, $definition);
		$this->addParentCondition($container, $definition);
	}

	/**
	 * Create the data provider definition in the container if not already set.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @return void
	 */
	protected function parseDataProvider(IMetaModelDataDefinition $container)
	{
		$config = $this->getDataProviderDefinition($container);

		// Check config if it already exists, if not, add it.
		if (!$config->hasInformation($container->getName()))
		{
			$providerInformation = new ContaoDataProviderInformation();
			$providerInformation->setName($container->getName());
			$config->addInformation($providerInformation);
		}
		else
		{
			$providerInformation = $config->getInformation($container->getName());
		}

		if ($providerInformation instanceof ContaoDataProviderInformation)
		{
			$providerInformation
				->setTableName($container->getName())
				->setClassName('MetaModels\DcGeneral\Data\Driver')
				->setInitializationData(array(
					'source' => $container->getName()
				))
				->isVersioningEnabled(false);
			$container->getBasicDefinition()->setDataProvider($container->getName());
		}

		// If in hierarchical mode, set the root provider.
		if ($container->getBasicDefinition()->getMode() == BasicDefinitionInterface::MODE_HIERARCHICAL)
		{
			$container->getBasicDefinition()->setRootDataProvider($container->getName());
		}

		// If not standalone, set the correct parent provider.
		if (!$this->getInputScreenDetails($container)->isStandalone())
		{
			$inputScreen = $this->getInputScreenDetails($container);

			// Check config if it already exists, if not, add it.
			if (!$config->hasInformation($inputScreen->getParentTable()))
			{
				$providerInformation = new ContaoDataProviderInformation();
				$providerInformation->setName($inputScreen->getParentTable());
				$config->addInformation($providerInformation);
			}
			else
			{
				$providerInformation = $config->getInformation($inputScreen->getParentTable());
			}

			if ($providerInformation instanceof ContaoDataProviderInformation)
			{
				$providerInformation
					->setTableName($inputScreen->getParentTable())
					->setInitializationData(array(
						'source' => $inputScreen->getParentTable()
					)
				);

				// How can we honor other drivers? We do only check for MetaModels and legacy SQL here.
				if (in_array($inputScreen->getParentTable(), Factory::getAllTables()))
				{
					$providerInformation
						->setClassName('MetaModels\DcGeneral\Data\Driver');
				}

				$container->getBasicDefinition()->setParentDataProvider($inputScreen->getParentTable());
			}
		}
	}

	/**
	 * Parse and build the backend view definition for the old Contao2 backend view.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @throws DcGeneralInvalidArgumentException When the contained view definition is of invalid type.
	 *
	 * @return void
	 */
	protected function parseBackendView(IMetaModelDataDefinition $container)
	{
		if ($container->hasDefinition(Contao2BackendViewDefinitionInterface::NAME))
		{
			$view = $container->getDefinition(Contao2BackendViewDefinitionInterface::NAME);
		}
		else
		{
			$view = new Contao2BackendViewDefinition();
			$container->setDefinition(Contao2BackendViewDefinitionInterface::NAME, $view);
		}

		if (!$view instanceof Contao2BackendViewDefinitionInterface)
		{
			throw new DcGeneralInvalidArgumentException(
				'Configured BackendViewDefinition does not implement Contao2BackendViewDefinitionInterface.'
			);
		}

		$this->parseListing($container, $view);
		$this->parseModelOperations($view, $container);
	}


	/**
	 * Parse the listing configuration.
	 *
	 * @param IMetaModelDataDefinition              $container The data container.
	 *
	 * @param Contao2BackendViewDefinitionInterface $view      The view definition.
	 *
	 * @return void
	 */
	protected function parseListing(IMetaModelDataDefinition $container, Contao2BackendViewDefinitionInterface $view)
	{
		$listing = $view->getListingConfig();

		if ($listing->getRootLabel() === null)
		{
			$listing->setRootLabel($this->getMetaModel($container)->get('name'));
		}

		if (($listing->getRootIcon() === null) && (($inputScreen = $this->getInputScreenDetails($container)) !== null))
		{
			$icon = ToolboxFile::convertValueToPath($inputScreen->getIcon());
			// Determine image to use.
			if ($icon && file_exists(TL_ROOT . '/' . $icon))
			{
				$event = new ResizeImageEvent($icon, 16, 16);
				$this->dispatcher->dispatch(ContaoEvents::IMAGE_RESIZE, $event);
				$icon = $event->getResultImage();
			} else {
				$icon = 'system/modules/metamodels/html/metamodels.png';
			}

			$listing->setRootIcon($icon);
		}

		$this->parseListSorting($container, $listing);
		$this->parseListLabel($container, $listing);
	}

	/**
	 * Generate a 16x16 pixel version of the passed image file. If this can not be done, the default image is returned.
	 *
	 * @param string $icon The name of the image file.
	 *
	 * @return null|string
	 */
	public function getBackendIcon($icon)
	{
		// Determine the image to use.
		if ($icon)
		{
			$icon = ToolboxFile::convertValueToPath($icon);

			/** @var ResizeImageEvent $event */
			$event = $this->dispatcher->dispatch(
				ContaoEvents::IMAGE_RESIZE,
				new ResizeImageEvent($icon, 16, 16)
			);

			if (file_exists(TL_ROOT . '/' . $event->getResultImage()))
			{
				return $event->getResultImage();
			}
		}

		return 'system/modules/metamodels/html/metamodels.png';
	}

	/**
	 * Parse the sorting part of listing configuration.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @param ListingConfigInterface   $listing   The listing configuration.
	 *
	 * @return void
	 */
	protected function parseListSorting(IMetaModelDataDefinition $container, ListingConfigInterface $listing)
	{
		$inputScreen = ViewCombinations::getInputScreenDetails($container->getName());

		$listing->setRootIcon($this->getBackendIcon($inputScreen->getIcon()));
	}

	/**
	 * Parse the sorting part of listing configuration.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @param ListingConfigInterface   $listing   The listing config.
	 *
	 * @return void
	 */
	protected function parseListLabel(IMetaModelDataDefinition $container, ListingConfigInterface $listing)
	{
		$providerName = $container->getBasicDefinition()->getDataProvider();
		if (!$listing->hasLabelFormatter($providerName))
		{
			$formatter = new DefaultModelFormatterConfig();
			$listing->setLabelFormatter($container->getBasicDefinition()->getDataProvider(), $formatter);
		}
		else
		{
			$formatter = $listing->getLabelFormatter($providerName);
		}

		$formatter->setPropertyNames(
			array_merge(
				$formatter->getPropertyNames(),
				$container->getPropertiesDefinition()->getPropertyNames()
			)
		);

		if (!$formatter->getFormat())
		{
			$formatter->setFormat(str_repeat('%s ', count($formatter->getPropertyNames())));
		}
	}

	/**
	 * Build a command into the the command collection.
	 *
	 * @param CommandCollectionInterface $collection      The command collection.
	 *
	 * @param string                     $operationName   The operation name.
	 *
	 * @param array                      $queryParameters The query parameters for the operation.
	 *
	 * @param string                     $icon            The icon to use in the backend.
	 *
	 * @param array                      $extraValues     The extra values for the command.
	 *
	 * @return Builder
	 */
	protected function createCommand(
		CommandCollectionInterface $collection,
		$operationName,
		$queryParameters,
		$icon,
		$extraValues
	)
	{
		if ($collection->hasCommandNamed($operationName))
		{
			$command = $collection->getCommandNamed($operationName);
		}
		else
		{
			switch ($operationName)
			{
				case 'cut':
					$command = new CutCommand();
					break;
				default:
					$command = new Command();
			}

			$command->setName($operationName);
			$collection->addCommand($command);
		}

		$parameters = $command->getParameters();
		foreach ($queryParameters as $name => $value)
		{
			if (!isset($parameters[$name]))
			{
				$parameters[$name] = $value;
			}
		}

		if (!$command->getLabel())
		{
			$command->setLabel($operationName . '.0');
		}

		if (!$command->getDescription())
		{
			$command->setDescription($operationName . '.1');
		}

		$extra         = $command->getExtra();
		$extra['icon'] = $icon;

		foreach ($extraValues as $name => $value)
		{
			if (!isset($extra[$name]))
			{
				$extra[$name] = $value;
			}
		}

		return $this;
	}

	/**
	 * Parse the defined model scoped operations and populate the definition.
	 *
	 * @param Contao2BackendViewDefinitionInterface $view      The backend view information.
	 *
	 * @param IMetaModelDataDefinition              $container The data container.
	 *
	 * @return void
	 */
	protected function parseModelOperations(Contao2BackendViewDefinitionInterface $view, IMetaModelDataDefinition $container)
	{
		$collection = $view->getModelCommands();
		$this->createCommand
		(
			$collection,
			'edit',
			array('act' => 'edit'),
			'edit.gif',
			array()
		)
		->createCommand
		(
			$collection,
			'copy',
			array('act' => ''),
			'copy.gif',
			array('attributes' => 'onclick="Backend.getScrollOffset();"')
		)
		->createCommand
		(
			$collection,
			'cut',
			array('act' => 'paste', 'mode' => 'cut'),
			'cut.gif',
			array(
				'attributes' => 'onclick="Backend.getScrollOffset();"'
			)
		)
		->createCommand
		(
			$collection,
			'delete',
			array('act' => 'delete'),
			'delete.gif',
			array(
				'attributes' => sprintf(
					'onclick="if (!confirm(\'%s\')) return false; Backend.getScrollOffset();"',
					// FIXME: we need the translation manager here.
					$GLOBALS['TL_LANG']['MSC']['deleteConfirm']
				)
			)
		)
		->createCommand
		(
			$collection,
			'show',
			array('act' => 'show'),
			'show.gif',
			array()
		);

		if ($this->getMetaModel($container))
		{
			$this->createCommand(
				$collection,
				'createvariant',
				array('act' => 'createvariant'),
				'system/modules/metamodels/html/variants.png',
				array()
			);
		}
	}

	protected function buildPropertyFromDca(
		IMetaModelDataDefinition $container,
		PropertiesDefinitionInterface $definition,
		$propName,
		IInputScreen $inputScreen
	)
	{
		$property = $inputScreen->getProperty($propName);
		$propInfo = $property['info'];

		if ($definition->hasProperty($propName))
		{
			$property = $definition->getProperty($propName);
		}
		else
		{
			$property = new DefaultProperty($propName);
			$definition->addProperty($property);
		}

		if (!$property->getLabel() && isset($propInfo['label']))
		{
			$lang = $propInfo['label'];

			if (is_array($lang))
			{
				$label       = reset($lang);
				$description = next($lang);

				$property->setDescription($description);
			}
			else {
				$label = $lang;
			}

			$property->setLabel($label);
		}

		if (!$property->getDescription() && isset($propInfo['description']))
		{
			$property->setDescription($propInfo['description']);
		}

		if (!$property->getDefaultValue() && isset($propInfo['default']))
		{
			$property->setDefaultValue($propInfo['default']);
		}

		if (isset($propInfo['exclude']))
		{
			$property->setExcluded($propInfo['exclude']);
		}

		if (isset($propInfo['search']))
		{
			$property->setSearchable($propInfo['search']);
		}

		if (isset($propInfo['sorting']))
		{
			$property->setSortable($propInfo['sorting']);
		}

		if (isset($propInfo['filter']))
		{
			$property->setFilterable($propInfo['filter']);
		}

		if (!$property->getGroupingLength() && isset($propInfo['length']))
		{
			$property->setGroupingLength($propInfo['length']);
		}

		if (!$property->getWidgetType() && isset($propInfo['inputType']))
		{
			$property->setWidgetType($propInfo['inputType']);
		}

		if (!$property->getOptions() && isset($propInfo['options']))
		{
			$property->setOptions($propInfo['options']);
		}

		if (!$property->getExplanation() && isset($propInfo['explanation']))
		{
			$property->setExplanation($propInfo['explanation']);
		}

		if (!$property->getExtra() && isset($propInfo['eval']))
		{
			$property->setExtra($propInfo['eval']);
		}
	}

	/**
	 * Parse the defined properties and populate the definition.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @return void
	 */
	protected function parseProperties(IMetaModelDataDefinition $container)
	{
		if ($container->hasPropertiesDefinition())
		{
			$definition = $container->getPropertiesDefinition();
		}
		else
		{
			$definition = new DefaultPropertiesDefinition();
			$container->setPropertiesDefinition($definition);
		}

		$metaModel   = Factory::byTableName($container->getName());
		$inputScreen = $this->getInputScreenDetails($container);

		foreach ($metaModel->getAttributes() as $attribute)
		{
			$this->buildPropertyFromDca($container, $definition, $attribute->getColName(), $inputScreen);

			$event = new BuildAttributeEvent($metaModel, $attribute, $container);
			// Trigger BuildAttribute Event.
			$this->dispatcher->dispatch($event::NAME, $event);
		}
	}

	/**
	 * Add a PropertyTrueCondition to the condition of the sub palette parent property if parent property is defined.
	 *
	 * @param string          $parentPropertyName The name of the parent property.
	 *
	 * @param array           $propInfo           The property definition from the dca.
	 *
	 * @param LegendInterface $paletteLegend      The legend where the property is contained.
	 *
	 * @return void
	 */
	protected function addSubPalette($parentPropertyName, $propInfo, LegendInterface $paletteLegend)
	{
		if ($propInfo['subpalette'])
		{
			foreach ($propInfo['subpalette'] as $propertyName)
			{
				$property = new Property($propertyName);
				$paletteLegend->addProperty($property);
				$property->setVisibleCondition(new PropertyTrueCondition($parentPropertyName));
			}
		}
	}

	/**
	 * Parse the palettes from the input screen into the data container.
	 *
	 * @param IMetaModelDataDefinition $container The data container.
	 *
	 * @return void
	 */
	protected function parsePalettes(IMetaModelDataDefinition $container)
	{
		$inputScreen = $this->getInputScreenDetails($container);
		$metaModel   = $this->getMetaModel($container);

		if ($container->hasDefinition(PalettesDefinitionInterface::NAME))
		{
			$palettesDefinition = $container->getDefinition(PalettesDefinitionInterface::NAME);
		}
		else
		{
			$palettesDefinition = new DefaultPalettesDefinition();
			$container->setDefinition(PalettesDefinitionInterface::NAME, $palettesDefinition);
		}

		$palette = new Palette();
		$palette
			->setName('default')
			->setCondition(new DefaultPaletteCondition());
		$palettesDefinition->addPalette($palette);

		foreach ($inputScreen->getLegends() as $legendName => $legend)
		{
			$paletteLegend = new Legend($legendName);
			$paletteLegend->setInitialVisibility($legend['visible']);
			$palette->addLegend($paletteLegend);

			$this->translator->setValue($legendName . '_legend', $legend['name'], $container->getName());

			foreach ($legend['properties'] as $propertyName)
			{
				$property = new Property($propertyName);
				$paletteLegend->addProperty($property);
				$propInfo = $inputScreen->getProperty($propertyName);

				$chain = new PropertyConditionChain();
				$property->setEditableCondition($chain);

				$chain->addCondition(new BooleanCondition(
					!(isset($propInfo['info']['readonly']) && $propInfo['info']['readonly'])
				));

				if ($metaModel->hasVariants() && !$metaModel->getAttribute($propertyName)->get('isvariant'))
				{
					$chain->addCondition(new PropertyValueCondition('varbase', 1));
				}

				$extra = $propInfo['info'];
				$chain = new PropertyConditionChain();
				$property->setVisibleCondition($chain);
				$chain->addCondition(new BooleanCondition(
					!((isset($extra['doNotShow']) && $extra['doNotShow'])
						|| (isset($extra['hideInput']) && $extra['hideInput']))
				));

				// If variants, do show only if allowed.
				if ($metaModel->hasVariants())
				{
					$chain->addCondition(new IsVariantAttribute());
				}

				$this->addSubPalette($propertyName, $propInfo, $paletteLegend);
			}
		}
	}
}