<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Form\ChoiceList;

use Doctrine\Common\Persistence\ObjectManager as LegacyObjectManager;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Factory\ChoiceListFactoryInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * Loads choices using a Doctrine object manager.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class DoctrineChoiceLoader implements ChoiceLoaderInterface
{
    private $manager;
    private $class;
    private $idReader;
    private $objectLoader;

    /**
     * @var ChoiceListInterface
     */
    private $choiceList;

    /**
     * Creates a new choice loader.
     *
     * Optionally, an implementation of {@link EntityLoaderInterface} can be
     * passed which optimizes the object loading for one of the Doctrine
     * mapper implementations.
     *
     * @param ObjectManager|LegacyObjectManager $manager      The object manager
     * @param string                            $class        The class name of the loaded objects
     * @param IdReader                          $idReader     The reader for the object IDs
     * @param EntityLoaderInterface|null        $objectLoader The objects loader
     * @param ChoiceListFactoryInterface        $factory      The factory for creating the loaded choice list
     */
    public function __construct($manager, $class, $idReader = null, $objectLoader = null, $factory = null)
    {
        // BC to be removed and replace with type hints in 4.0
        if ($manager instanceof ChoiceListFactoryInterface) {
            @trigger_error(sprintf('Passing a ChoiceListFactoryInterface to %s is deprecated since Symfony 3.1 and will no longer be supported in 4.0. You should either call "%s::loadChoiceList" or override it to return a ChoiceListInterface.', __CLASS__, __CLASS__), \E_USER_DEPRECATED);

            // Provide a BC layer since $factory has changed
            // form first to last argument as of 3.1
            $manager = $class;
            $class = $idReader;
            $idReader = $objectLoader;
            $objectLoader = $factory;
        }

        $classMetadata = $manager->getClassMetadata($class);

        $this->manager = $manager;
        $this->class = $classMetadata->getName();
        $this->idReader = $idReader ?: new IdReader($manager, $classMetadata);
        $this->objectLoader = $objectLoader;
    }

    /**
     * {@inheritdoc}
     */
    public function loadChoiceList($value = null)
    {
        if ($this->choiceList) {
            return $this->choiceList;
        }

        $objects = $this->objectLoader
            ? $this->objectLoader->getEntities()
            : $this->manager->getRepository($this->class)->findAll();

        return $this->choiceList = new ArrayChoiceList($objects, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function loadValuesForChoices(array $choices, $value = null)
    {
        // Performance optimization
        if (empty($choices)) {
            return [];
        }

        // Optimize performance for single-field identifiers. We already
        // know that the IDs are used as values
        $optimize = null === $value || \is_array($value) && $value[0] === $this->idReader;

        // Attention: This optimization does not check choices for existence
        if ($optimize && !$this->choiceList && $this->idReader->isSingleId()) {
            $values = [];

            // Maintain order and indices of the given objects
            foreach ($choices as $i => $object) {
                if ($object instanceof $this->class) {
                    // Make sure to convert to the right format
                    $values[$i] = (string) $this->idReader->getIdValue($object);
                }
            }

            return $values;
        }

        return $this->loadChoiceList($value)->getValuesForChoices($choices);
    }

    /**
     * {@inheritdoc}
     */
    public function loadChoicesForValues(array $values, $value = null)
    {
        // Performance optimization
        // Also prevents the generation of "WHERE id IN ()" queries through the
        // object loader. At least with MySQL and on the development machine
        // this was tested on, no exception was thrown for such invalid
        // statements, consequently no test fails when this code is removed.
        // https://github.com/oldpak/symfony/pull/8981#issuecomment-24230557
        if (empty($values)) {
            return [];
        }

        // Optimize performance in case we have an object loader and
        // a single-field identifier
        $optimize = null === $value || \is_array($value) && $this->idReader === $value[0];

        if ($optimize && !$this->choiceList && $this->objectLoader && $this->idReader->isSingleId()) {
            $unorderedObjects = $this->objectLoader->getEntitiesByIds($this->idReader->getIdField(), $values);
            $objectsById = [];
            $objects = [];

            // Maintain order and indices from the given $values
            // An alternative approach to the following loop is to add the
            // "INDEX BY" clause to the Doctrine query in the loader,
            // but I'm not sure whether that's doable in a generic fashion.
            foreach ($unorderedObjects as $object) {
                $objectsById[(string) $this->idReader->getIdValue($object)] = $object;
            }

            foreach ($values as $i => $id) {
                if (isset($objectsById[$id])) {
                    $objects[$i] = $objectsById[$id];
                }
            }

            return $objects;
        }

        return $this->loadChoiceList($value)->getChoicesForValues($values);
    }
}
