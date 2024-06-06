<?php

namespace Kikwik\DoctrineRelationCountBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Kikwik\DoctrineRelationCountBundle\Attribute\CountableEntity;
use Kikwik\DoctrineRelationCountBundle\Attribute\CountableRelation;
use Symfony\Component\PropertyAccess\PropertyAccess;

class RelationCounterListener
{
    public function __construct(
        private Registry $doctrine
    )
    {
    }

    public function prePersist(PrePersistEventArgs $eventArgs)      {$this->saveActualValues($eventArgs);}
    public function postPersist(PostPersistEventArgs $eventArgs)    {$this->updateCounters($eventArgs);}
    public function preUpdate(PreUpdateEventArgs $eventArgs)        {$this->saveChangedValues($eventArgs);}
    public function postUpdate(PostUpdateEventArgs $eventArgs)      {$this->updateCounters($eventArgs);}
    public function preRemove(PreRemoveEventArgs $eventArgs)        {$this->saveActualValues($eventArgs);}
    public function postRemove(PostRemoveEventArgs $eventArgs)      {$this->updateCounters($eventArgs);}

    /**
     * PrePersist and PreRemove - get the current value for all the CountableRelation objetcs
     *
     * @param mixed $eventArgs
     * @return void
     */
    private function saveActualValues(mixed $eventArgs)
    {
        if($localObject = $this->getEntityIfSupported($eventArgs))
        {
            $propertyAccessor = PropertyAccess::createPropertyAccessor();
            $relationNames = $this->getCountableRelations($localObject);
            foreach($relationNames as $relationName)
            {
                $this->addObjectToUpdate($localObject, $relationName, $propertyAccessor->getValue($localObject, $relationName));
            }
        }
    }

    /**
     * PreUpdate - get the old and new value for the changed CountableRelation objects
     *
     * @param PreUpdateEventArgs $eventArgs
     * @return void
     */
    private function saveChangedValues(PreUpdateEventArgs $eventArgs)
    {
        if($localObject = $this->getEntityIfSupported($eventArgs))
        {
            $relationNames = $this->getCountableRelations($localObject);
            foreach($relationNames as $relationName)
            {
                if($eventArgs->hasChangedField($relationName))
                {
                    $this->addObjectToUpdate($localObject, $relationName, $eventArgs->getOldValue($relationName));
                    $this->addObjectToUpdate($localObject, $relationName, $eventArgs->getNewValue($relationName));
                }
            }
        }
    }

    /**
     * PostPersist, PostUpdate and PostRemove - Call the updateNumProdotti repository method for all the changed objects
     *
     * @return void
     */
    private function updateCounters(mixed $eventArgs)
    {
        foreach($this->objectsToUpdate as $objectData)
        {
            $localObject = $objectData['localObject'];
            $relatedObject = $objectData['relatedObject'];
            if($relatedObject)
            {
                $relatedRepository = $this->doctrine->getManager()->getRepository(get_class($relatedObject));
                if(method_exists($relatedRepository, 'updateCountableRelation')) {
                    // Call updateCountableRelation method
                    $relatedRepository->updateCountableRelation($localObject, $objectData['relationName'], $relatedObject, $objectData['relatedProperty']);
                } else {
                    // Method updateCountableRelation does not exist
                    $dql = sprintf('UPDATE %s related set related.%s = (SELECT COUNT(local.id) FROM %s local WHERE local.%s = :id) WHERE related.id = :id',
                        get_class($relatedObject),
                        $objectData['relatedProperty'],
                        get_class($localObject),
                        $objectData['relationName'],
                    );
                    $query = $this->doctrine->getManager()->createQuery($dql)
                        ->setParameter('id', $relatedObject->getId());
                    $query->execute();
                }
            }
        }
        // reset objectsToUpdate variable
        $this->objectsToUpdate = [];
    }

    private $objectsToUpdate = [];

    /**
     * Adds an object to the update queue for countable relations.
     *
     * @param mixed $localObject The local object to add to the update queue.
     * @param string $relationName The name of the countable relation.
     * @param mixed $relatedObject The related object to update.
     * @return void
     * @throws \Exception If the targetProperty is not defined in the #[CountableRelation] attribute.
     */
    private function addObjectToUpdate(mixed $localObject, string $relationName, mixed $relatedObject)
    {
        $reflectionClass = new \ReflectionClass($localObject);
        $reflectionProperty = $reflectionClass->getProperty($relationName);
        $countableAttributes = $reflectionProperty->getAttributes(CountableRelation::class);
        foreach($countableAttributes as $countableAttribute)
        {
            $countableArguments = $countableAttribute->getArguments();
            if(!isset($countableArguments['targetProperty']))
            {
                throw new \Exception(sprintf('targetProperty not defined in attribute #[CountableRelation] for %s::%s', get_class($localObject), $relationName));
            }
            $this->objectsToUpdate[] = [
                'localObject' => $localObject,
                'relatedObject' => $relatedObject,
                'relatedProperty' => $countableArguments['targetProperty'],
                'relationName' => $relationName,
            ];
        }
    }

    /**
     * Retrieves the entity if it is supported (has the CountableEntity attribute)
     *
     * @param mixed $eventArgs The event arguments
     * @return object|null The entity if it is supported, null otherwise
     */
    private function getEntityIfSupported($eventArgs): ?object
    {
        $entity = $eventArgs->getObject();
        $reflectionClass = new \ReflectionClass(get_class($entity));
        if ($reflectionClass->getAttributes(CountableEntity::class))
        {
            return $entity;
        }
        return null;
    }

    /**
     * Returns an array of field names for the given object that have a countable relation.
     *
     * @param mixed $object The object to check for countable relations.
     * @return array An array of field names with countable relations.
     */
    private function getCountableRelations(mixed $object): array
    {
        $classMetadata = $this->doctrine->getManager()->getClassMetadata(get_class($object));
        $reflectionClass = new \ReflectionClass($object);
        $fieldsWithCountableRelation = [];
        foreach ($classMetadata->associationMappings as $fieldName => $mapping)
        {
            $reflectionProperty = $reflectionClass->getProperty($fieldName);
            $countableAttributes = $reflectionProperty->getAttributes(CountableRelation::class);
            if(count($countableAttributes))
            {
                if ($mapping['type'] === ClassMetadata::MANY_TO_ONE )   // || $mapping['type'] === ClassMetadata::MANY_TO_MANY
                {
                    $fieldsWithCountableRelation[] = $fieldName;
                }
                else
                {
                    throw new \Exception(sprintf('Found a #[CountableRelation] attribute on property "%s" in "%s". CountableRelation supports ManyToOne', $fieldName, get_class($object)));
                }
            }
        }
        return $fieldsWithCountableRelation;
    }
}