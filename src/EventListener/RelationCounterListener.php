<?php

namespace Kikwik\DoctrineRelationCountBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Kikwik\DoctrineRelationCountBundle\Attribute\CountableEntity;
use Kikwik\DoctrineRelationCountBundle\Attribute\CountableRelation;
use Symfony\Component\PropertyAccess\PropertyAccess;

class RelationCounterListener
{
    public function __construct(
        private readonly Registry $doctrine
    )
    {
    }

    public function prePersist(PrePersistEventArgs $eventArgs)      {$this->saveActualValues($eventArgs);}
    public function preRemove(PreRemoveEventArgs $eventArgs)        {$this->saveActualValues($eventArgs);}
    public function onFlush(OnFlushEventArgs $eventArgs)            {$this->saveChangedValues($eventArgs);}
    public function postFlush(PostFlushEventArgs $eventArgs)        {$this->updateCounters();}

    /**
     * PrePersist and PreRemove - get the current value for all the CountableRelation objects
     *
     * @param mixed $eventArgs
     * @return void
     * @throws \ReflectionException
     */
    private function saveActualValues(mixed $eventArgs): void
    {
        $localObject = $eventArgs->getObject();
        if($this->isEntitySupported($localObject))
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
     * onFlush - get the old and new values for the changed CountableRelation objects
     *
     * @param OnFlushEventArgs $eventArgs
     * @return void
     * @throws \ReflectionException
     */
    public function saveChangedValues(OnFlushEventArgs $eventArgs): void
    {
        $em = $eventArgs->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $localObject)
        {
            if ($this->isEntitySupported($localObject))
            {
                $relationNames = $this->getCountableRelations($localObject);

                // check for ManyToOne relations
                $changeset = $uow->getEntityChangeSet($localObject);
                foreach($relationNames as $relationName)
                {
                    if(isset($changeset[$relationName]))
                    {
                        foreach($changeset[$relationName] as $relatedObject)
                        {
                            $this->addObjectToUpdate($localObject, $relationName, $relatedObject);
                        }
                    }
                }

                // check for ManyToMany relations
                foreach ($uow->getScheduledCollectionUpdates() as $collectionUpdate)
                {
                    $relationName = $collectionUpdate->getMapping()['fieldName'];
                    if ($this->isEntitySupported($collectionUpdate->getOwner()) && in_array($relationName, $relationNames))
                    {
                        $oldRelatedObjects = $collectionUpdate->getSnapshot();
                        $newRelatedObjects = $collectionUpdate->toArray();

                        // Elements that have been removed or added
                        $removedObjects = array_diff($oldRelatedObjects, $newRelatedObjects);
                        $addedObjects = array_diff($newRelatedObjects, $oldRelatedObjects);

                        // Merge arrays and remove duplicates
                        $changedObjects = array_unique(array_merge($removedObjects, $addedObjects));

                        $this->addObjectToUpdate($localObject, $relationName, $changedObjects);
                    }
                }

            }
        }
    }


    /**
     * postFlush - Call the updateCountableRelation method in the related repository
     *              or run the update cunters query
     *
     * @return void
     */
    private function updateCounters(): void
    {
        foreach($this->objectsToUpdate as $objectData)
        {
            $localObject = $objectData['localObject'];
            if(is_iterable($objectData['relatedObject']))
            {
                // collection, relation is ManyToMany
                $relatedObjects = $objectData['relatedObject'];
                foreach($relatedObjects as $relatedObject)
                {
                    $relatedRepository = $this->doctrine->getManager()->getRepository(get_class($relatedObject));
                    if(method_exists($relatedRepository, 'updateCountableRelation')) {
                        // Call updateCountableRelation method
                        $relatedRepository->updateCountableRelation($localObject, $objectData['relationName'], $relatedObject, $objectData['relatedProperty']);
                    } else {
                        // Method updateCountableRelation does not exist
                        $countDql = sprintf('SELECT COUNT(local.id) FROM %s local LEFT JOIN local.%s related WHERE related.id = :id',
                            get_class($localObject),
                            $objectData['relationName'],
                        );
                        $countQuery = $this->doctrine->getManager()->createQuery($countDql)
                            ->setParameter('id', $relatedObject->getId());
                        $count = $countQuery->getSingleScalarResult();

                        $updateDql = sprintf('UPDATE %s related set related.%s = :count WHERE related.id = :id',
                            get_class($relatedObject),
                            $objectData['relatedProperty'],
                        );
                        $updateQuery = $this->doctrine->getManager()->createQuery($updateDql)
                            ->setParameter('id', $relatedObject->getId())
                            ->setParameter('count', $count);
                        $updateQuery->execute();
                    }
                }
            }
            else
            {
                // object, relation is ManyToOne
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
        }
        // reset objectsToUpdate variable
        $this->objectsToUpdate = [];
    }

    private array $objectsToUpdate = [];

    /**
     * Adds an object to the update queue for countable relations.
     *
     * @param mixed $localObject The local object to add to the update queue.
     * @param string $relationName The name of the countable relation.
     * @param mixed $relatedObject The related object to update.
     * @return void
     * @throws \Exception If the targetProperty is not defined in the #[CountableRelation] attribute.
     */
    private function addObjectToUpdate(mixed $localObject, string $relationName, mixed $relatedObject): void
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
     * Check if the entity is supported (has the CountableEntity attribute)
     *
     * @param object $entity
     * @return bool
     */
    private function isEntitySupported(object $entity): bool
    {
        $reflectionClass = new \ReflectionClass(get_class($entity));
        return (bool)count($reflectionClass->getAttributes(CountableEntity::class));
    }

    /**
     * Returns an array of field names for the given object that have a countable relation.
     *
     * @param mixed $object The object to check for countable relations.
     * @return array An array of field names with countable relations.
     * @throws \ReflectionException
     * @throws \Exception If the #[CountableRelation] is associated with a non ManyToOne or ManyToMany relation.
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
                if ($mapping['type'] === ClassMetadata::MANY_TO_ONE || $mapping['type'] === ClassMetadata::MANY_TO_MANY)
                {
                    $fieldsWithCountableRelation[] = $fieldName;
                }
                else
                {
                    throw new \Exception(sprintf('Unsupported relation type in attribute #[CountableRelation] for %s::%s. CountableRelation supports only ManyToOne or ManyToMany', $fieldName, get_class($object)));
                }
            }
        }
        return $fieldsWithCountableRelation;
    }
}