<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * POS order payment row data interface.
 *
 * Represents a row in `panth_pos_order_payment` - one tender row of a
 * (possibly split) POS payment. Change given back is stored as a negative
 * amount row with is_change = 1.
 */
interface PosOrderPaymentInterface
{
    public const PAYMENT_ID   = 'payment_id';
    public const POS_ORDER_ID = 'pos_order_id';
    public const METHOD_CODE  = 'method_code';
    public const METHOD_TITLE = 'method_title';
    public const AMOUNT       = 'amount';
    public const REFERENCE    = 'reference';
    public const IS_CHANGE    = 'is_change';
    public const CREATED_AT   = 'created_at';

    /**
     * Get payment row id.
     *
     * @return int|null
     */
    public function getPaymentId(): ?int;

    /**
     * Set payment row id.
     *
     * @param int $paymentId
     * @return $this
     */
    public function setPaymentId(int $paymentId): self;

    /**
     * Get POS order id.
     *
     * @return int
     */
    public function getPosOrderId(): int;

    /**
     * Set POS order id.
     *
     * @param int $posOrderId
     * @return $this
     */
    public function setPosOrderId(int $posOrderId): self;

    /**
     * Get POS payment method code.
     *
     * @return string
     */
    public function getMethodCode(): string;

    /**
     * Set POS payment method code.
     *
     * @param string $methodCode
     * @return $this
     */
    public function setMethodCode(string $methodCode): self;

    /**
     * Get POS payment method title (snapshot at order time).
     *
     * @return string
     */
    public function getMethodTitle(): string;

    /**
     * Set POS payment method title (snapshot at order time).
     *
     * @param string $methodTitle
     * @return $this
     */
    public function setMethodTitle(string $methodTitle): self;

    /**
     * Get amount (negative for change rows).
     *
     * @return float
     */
    public function getAmount(): float;

    /**
     * Set amount (negative for change rows).
     *
     * @param float $amount
     * @return $this
     */
    public function setAmount(float $amount): self;

    /**
     * Get external reference (terminal receipt no, check no, ...).
     *
     * @return string|null
     */
    public function getReference(): ?string;

    /**
     * Set external reference (terminal receipt no, check no, ...).
     *
     * @param string|null $reference
     * @return $this
     */
    public function setReference(?string $reference): self;

    /**
     * Get change flag (1 = this row is change returned to the customer).
     *
     * @return int
     */
    public function getIsChange(): int;

    /**
     * Set change flag (1 = this row is change returned to the customer).
     *
     * @param int $isChange
     * @return $this
     */
    public function setIsChange(int $isChange): self;

    /**
     * Get creation timestamp.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * Set creation timestamp.
     *
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt(string $createdAt): self;
}
