<?php

/*
 * This file is part of the OpenMiamMiam project.
 *
 * (c) Isics <contact@isics.fr>
 *
 * This source file is subject to the AGPL v3 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Isics\Bundle\OpenMiamMiamBundle\Entity\Repository;

use Doctrine\ORM\EntityRepository;

class AssociationRepository extends EntityRepository
{
    /**
     * Finds all ids
     *
     * @return array
     */
    public function findAllIds()
    {
        $ids = $this->createQueryBuilder('a')
                ->select('a.id')
                ->getQuery()
                ->getResult();

        $flattenIds = array();
        foreach ($ids as $id) {
            $flattenIds[] = $id['id'];
        }

        return $flattenIds;
    }
}
