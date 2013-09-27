<?php

/*
 * This file is part of the OpenMiamMiam project.
 *
 * (c) Isics <contact@isics.fr>
 *
 * This source file is subject to the AGPL v3 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Isics\Bundle\OpenMiamMiamBundle\Manager;

use Doctrine\ORM\EntityManager;
use Isics\Bundle\OpenMiamMiamBundle\Entity\BranchOccurrence;
use Isics\Bundle\OpenMiamMiamBundle\Entity\Product;
use Isics\Bundle\OpenMiamMiamBundle\Entity\SalesOrder;
use Isics\Bundle\OpenMiamMiamBundle\Entity\SalesOrderRow;
use Isics\Bundle\OpenMiamMiamUserBundle\Entity\User;
use Isics\Bundle\OpenMiamMiamBundle\Model\Cart\Cart;
use Isics\Bundle\OpenMiamMiamBundle\Model\SalesOrder\SalesOrderConfirmation;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

/**
 * Class SalesOrderManager
 * Manager for sales order
 */
class SalesOrderManager
{
    /**
     * @var EntityManager $entityManager
     */
    protected $entityManager;

    /**
     * @var array $config
     */
    protected $config;

    /**
     * @var ActivityManager $activityManager
     */
    protected $activityManager;



    /**
     * Constructs object
     *
     * @param array $config
     * @param EntityManager $entityManager
     * @param ActivityManager $activityManager
     */
    public function __construct(array $config, EntityManager $entityManager, ActivityManager $activityManager)
    {
        $this->entityManager = $entityManager;
        $this->activityManager = $activityManager;

        $resolver = new OptionsResolver();
        $this->setDefaultOptions($resolver);
        $this->config = $resolver->resolve($config);
    }

    /**
     * Set the defaults options
     *
     * @param OptionsResolverInterface $resolver
     */
    protected function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setRequired(array('ref_prefix', 'ref_pad_length'));
    }

    /**
     * Creates sales order from a cart
     *
     * @param Cart $cart
     * @param BranchOccurrence $branchOccurrence
     * @param User $user
     * @param SalesOrderConfirmation $confirmation
     *
     * @return SalesOrder
     */
    public function processSalesOrderFromCart(Cart $cart,
                                      BranchOccurrence $branchOccurrence,
                                      User $user,
                                      SalesOrderConfirmation $confirmation = null)
    {
        $order = $this->createFromCart($cart, $branchOccurrence, $user);

        if (null !== $confirmation) {
            $order->setConsumerComment($confirmation->getConsumerComment());
        }

        $this->save($order, $order->getBranchOccurrence()->getBranch()->getAssociation(), $user);

        $cart->clearItems();

        return $order;
    }

    /**
     * Creates sales order from a cart
     *
     * @param Cart $cart
     * @param BranchOccurrence $branchOccurrence
     * @param User $user
     *
     * @return SalesOrder
     */
    public function createFromCart(Cart $cart, BranchOccurrence $branchOccurrence, User $user)
    {
        $order = new SalesOrder();

        $order->setDate(new \DateTime());

        $order->setBranchOccurrence($branchOccurrence);

        $order->setUser($user);
        $order->setFirstname($user->getFirstname());
        $order->setLastname($user->getLastname());
        $order->setAddress1($user->getAddress1());
        $order->setAddress2($user->getAddress2());
        $order->setZipcode($user->getZipcode());
        $order->setCity($user->getCity());

        foreach ($cart->getItems() as $item) {
            $product = $item->getProduct();

            $orderRow = new SalesOrderRow();
            $orderRow->setProduct($product);
            $orderRow->setProducer($product->getProducer());
            $orderRow->setName($product->getName());
            $orderRow->setRef($product->getRef());
            $orderRow->setIsBio($product->getIsBio());
            $orderRow->setUnitPrice($product->getPrice());
            $orderRow->setQuantity($item->getQuantity());

            $order->addSalesOrderRow($orderRow);
        }

        return $order;
    }

    /**
     * Saves sales order
     *
     * @param SalesOrder $order
     * @param mixed $context
     * @param User $user
     */
    public function save(SalesOrder $order, $context, User $user = null)
    {
        // Compute order's data (total...)
        $order->compute();

        $activitiesStack = array();

        if (null === $order->getId()) {
            // Increase reference for order
            $association = $order->getBranchOccurrence()->getBranch()->getAssociation();
            $association->setOrderRefCounter($association->getOrderRefCounter()+1);
            $this->entityManager->persist($association);

            // Sets ref
            $order->setRef(sprintf(
                '%s%s',
                $this->config['ref_prefix'],
                str_pad($association->getOrderRefCounter(), $this->config['ref_pad_length'], '0', STR_PAD_LEFT)
            ));

            $activitiesStack[] = $this->activityManager->createFromEntities(
                'activity_stream.sales_order.created',
                array('%ref%' => $order->getRef()),
                $order,
                $context,
                $user
            );
        } else {
            $unitOfWork = $this->entityManager->getUnitOfWork();
            $unitOfWork->computeChangeSets();
            foreach ($order->getSalesOrderRows() as $row) {
                $changeSet = $unitOfWork->getEntityChangeSet($row);
                if (!empty($changeSet)) {
                    $transKey = null;
                    if (isset($changeSet['quantity']) && $changeSet['quantity'][0] != $changeSet['quantity'][1]
                            && isset($changeSet['total']) && $changeSet['total'][0] != $changeSet['total'][1]) {
                        $transKey = 'activity_stream.sales_order.row.quantity_total_updated';
                        $transParams = array(
                            '%order_ref%' => $order->getRef(),
                            '%ref%' => $row->getRef(),
                            '%old_quantity%' => $this->activityManager->formatFloatNumber($changeSet['quantity'][0]),
                            '%quantity%' => $this->activityManager->formatFloatNumber($row->getQuantity()),
                            '%old_total%' => $this->activityManager->formatFloatNumber($changeSet['total'][0]),
                            '%total%' => $this->activityManager->formatFloatNumber($row->getTotal())
                        );
                    } elseif (isset($changeSet['quantity']) && $changeSet['quantity'][0] != $changeSet['quantity'][1]) {
                        $transKey = 'activity_stream.sales_order.row.quantity_updated';
                        $transParams = array(
                            '%order_ref%' => $order->getRef(),
                            '%ref%' => $row->getRef(),
                            '%old_quantity%' => $this->activityManager->formatFloatNumber($changeSet['quantity'][0]),
                            '%quantity%' => $this->activityManager->formatFloatNumber($row->getQuantity())
                        );
                    } elseif (isset($changeSet['total']) && $changeSet['total'][0] != $changeSet['total'][1]) {
                        $transKey = 'activity_stream.sales_order.row.total_updated';
                        $transParams = array(
                            '%order_ref%' => $order->getRef(),
                            '%ref%' => $row->getRef(),
                            '%old_total%' => $this->activityManager->formatFloatNumber($changeSet['total'][0]),
                            '%total%' => $this->activityManager->formatFloatNumber($row->getTotal())
                        );
                    }

                    if (null !== $transKey) {
                        $activitiesStack[] = $this->activityManager->createFromEntities($transKey, $transParams, $order, $context, $user);
                    }
                }
            }
        }

        // Update product stocks
        foreach ($order->getSalesOrderRows() as $row) {
            $product = $row->getProduct();
            if (null !== $product && $product->getAvailability() == Product::AVAILABILITY_ACCORDING_TO_STOCK) {
                $product->setStock(($product->getStock()-($row->getQuantity()-$row->getOldQuantity())));
                $this->entityManager->persist($product);
            }
        }

        // Save
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        // Activity
        foreach ($activitiesStack as $activity) {
            $this->entityManager->persist($activity);
        }

        $this->entityManager->flush();
    }
}
