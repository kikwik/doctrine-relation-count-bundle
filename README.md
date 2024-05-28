Kikwik/DoctrineRelationCountBundle
==================================

Manage counter as database property for doctrine relations

## Installation

1. require the bundle

```console
#!/bin/bash
composer require kikwik/doctrine-relation-count-bundle
```

## Usage

* Add an integer field in the related class wich will contain the count value

```php

#[ORM\Entity(repositoryClass: FamigliaRepository::class)]
class Famiglia
{
    #[ORM\Column]
    private int $numProdotti = 0;

    public function getNumProdotti(): int
    {
        return $this->numProdotti;
    }
}

```

* Add the `#[CountableEntity]` attribute on top of your child class  
* Add the `#[CountableRelation]` attribute on the ManyToOne relations which you would count
* Set the `targetProperty` parameter to the count property in the related class

```php

use Kikwik\DoctrineRelationCountBundle\Attribute\CountableEntity;
use Kikwik\DoctrineRelationCountBundle\Attribute\CountableRelation;

#[ORM\Entity(repositoryClass: ProdottoRepository::class)]
#[CountableEntity]
class Prodotto 
{
    #[ORM\ManyToOne(inversedBy: 'prodotti')]
    #[CountableRelation(targetProperty: 'numProdotti')]
    private ?Famiglia $famiglia = null;

}
```

## Custom updater

If you need to use a customized query for the counter update you can define a `updateCountableRelation` method in your child repository

```php

class ProdottoRepository extends ServiceEntityRepository
{
    public function updateCountableRelation(object $localObject, string $relationName, object $relatedObject, string $relatedProperty)
    {
        $dql = sprintf('UPDATE %s related set related.%s = (SELECT COUNT(local.id) FROM %s local WHERE local.%s = :id AND local.isActive = 1) WHERE related.id = :id',
                        get_class($relatedObject),
                        $relatedProperty,
                        get_class($localObject),
                        $relationName,
                    );

        $query = $this->getEntityManager()->createQuery($dql)
            ->setParameter('id', $relatedObject->getId());
        $query->execute();
    }
}

```
