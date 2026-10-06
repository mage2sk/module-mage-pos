<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * POS register data interface.
 *
 * Represents a row in `panth_pos_register` - a physical till / point-of-sale
 * terminal bound to a store view, with its own receipt header/footer.
 */
interface RegisterInterface
{
    public const REGISTER_ID    = 'register_id';
    public const NAME           = 'name';
    public const CODE           = 'code';
    public const STORE_ID       = 'store_id';
    public const STATUS         = 'status';
    public const RECEIPT_HEADER = 'receipt_header';
    public const RECEIPT_FOOTER = 'receipt_footer';
    public const SOURCE_CODE    = 'source_code';
    public const CREATED_AT     = 'created_at';
    public const UPDATED_AT     = 'updated_at';

    /**
     * Get register id.
     *
     * @return int|null
     */
    public function getRegisterId(): ?int;

    /**
     * Set register id.
     *
     * @param int $registerId
     * @return $this
     */
    public function setRegisterId(int $registerId): self;

    /**
     * Get register name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Set register name.
     *
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

    /**
     * Get unique register code.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Set unique register code.
     *
     * @param string $code
     * @return $this
     */
    public function setCode(string $code): self;

    /**
     * Get store view id the register belongs to.
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Set store view id the register belongs to.
     *
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self;

    /**
     * Get status (1 = enabled, 0 = disabled).
     *
     * @return int
     */
    public function getStatus(): int;

    /**
     * Set status (1 = enabled, 0 = disabled).
     *
     * @param int $status
     * @return $this
     */
    public function setStatus(int $status): self;

    /**
     * Get receipt header text.
     *
     * @return string|null
     */
    public function getReceiptHeader(): ?string;

    /**
     * Set receipt header text.
     *
     * @param string|null $receiptHeader
     * @return $this
     */
    public function setReceiptHeader(?string $receiptHeader): self;

    /**
     * Get receipt footer text.
     *
     * @return string|null
     */
    public function getReceiptFooter(): ?string;

    /**
     * Set receipt footer text.
     *
     * @param string|null $receiptFooter
     * @return $this
     */
    public function setReceiptFooter(?string $receiptFooter): self;

    /**
     * Get the MSI inventory source code this register checks salability against
     * and deducts stock from (NULL = Magento default stock / non-MSI behavior).
     *
     * @return string|null
     */
    public function getSourceCode(): ?string;

    /**
     * Set the MSI inventory source code this register is bound to.
     *
     * @param string|null $sourceCode
     * @return $this
     */
    public function setSourceCode(?string $sourceCode): self;

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

    /**
     * Get last update timestamp.
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * Set last update timestamp.
     *
     * @param string $updatedAt
     * @return $this
     */
    public function setUpdatedAt(string $updatedAt): self;
}
