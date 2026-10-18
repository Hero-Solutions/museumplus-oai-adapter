<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecordRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecordRepository::class)]
#[ORM\Table(name: 'records')]
#[ORM\UniqueConstraint(name: 'uniq_records_oai_identifier', columns: ['oai_identifier'])]
#[ORM\UniqueConstraint(name: 'uniq_records_axiell_priref', columns: ['axiell_priref'])]
#[ORM\Index(name: 'idx_records_datestamp_id', columns: ['datestamp', 'id'])]
#[ORM\Index(name: 'idx_records_set_datestamp', columns: ['set_spec', 'datestamp'])]
#[ORM\Index(name: 'idx_records_museumplus_id', columns: ['museumplus_id'])]
#[ORM\Index(name: 'idx_records_publish_msk_datestamp_id', columns: ['publish_msk', 'datestamp', 'id'])]
#[ORM\Index(name: 'idx_records_publish_brugge_datestamp_id', columns: ['publish_brugge', 'datestamp', 'id'])]
#[ORM\Index(name: 'idx_records_publish_muzee_datestamp_id', columns: ['publish_muzee', 'datestamp', 'id'])]
#[ORM\Index(name: 'idx_records_publish_vkc_datestamp_id', columns: ['publish_vkc', 'datestamp', 'id'])]
final class Record
{
    public const PUBLICATION_FIELDS = ['mskObj' => 'publishMsk', 'musea_brugge' => 'publishBrugge', 'muzee' => 'publishMuzee', 'vkc' => 'publishVkc'];
    public const PUBLICATION_COLUMNS = ['mskObj' => 'publish_msk', 'musea_brugge' => 'publish_brugge', 'muzee' => 'publish_muzee', 'vkc' => 'publish_vkc'];

    #[ORM\Column(options: ['default' => false])]
    private bool $publishMsk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $publishBrugge = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $publishMuzee = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $publishVkc = false;

    #[ORM\Column(type: Types::TEXT, nullable: true, columnDefinition: 'LONGTEXT DEFAULT NULL')]
    private ?string $oaiXmlNative = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $axiellPriref = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $rawResponseId = null;

    public function getRawResponseId(): ?string
    {
        return $this->rawResponseId;
    }

    public function setRawResponseId(?string $id): self
    {
        $this->rawResponseId = $id;

        return $this;
    }

    public function getAxiellPriref(): ?string
    {
        return $this->axiellPriref;
    }

    public function setAxiellPriref(?string $priref): self
    {
        $this->axiellPriref = $priref;

        return $this;
    }

    public function getSetSpecs(): array
    {
        return array_keys(array_filter(self::PUBLICATION_FIELDS, fn (string $field): bool => $this->$field));
    }

    public function setPublicationSets(array $sets): self
    {
        foreach (self::PUBLICATION_FIELDS as $set => $field) $this->$field = in_array($set, $sets, true);

        return $this;
    }

    public function getOaiXmlNative(): ?string
    {
        return $this->oaiXmlNative;
    }

    public function setOaiXmlNative(?string $xml): self
    {
        $this->oaiXmlNative = $xml;

        return $this;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $museumplusId;

    #[ORM\Column(length: 255)]
    private string $oaiIdentifier;

    #[ORM\Column(length: 128)]
    private string $setSpec;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $datestamp;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $objectNumber = null;

    #[ORM\Column(type: Types::TEXT, columnDefinition: 'LONGTEXT NOT NULL')]
    private string $oaiXml;

    #[ORM\Column(type: Types::TEXT, columnDefinition: 'LONGTEXT NOT NULL')]
    private string $museumplusXml;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMuseumplusId(): string
    {
        return $this->museumplusId;
    }

    public function setMuseumplusId(string $museumplusId): self
    {
        $this->museumplusId = $museumplusId;

        return $this;
    }

    public function getOaiIdentifier(): string
    {
        return $this->oaiIdentifier;
    }

    public function setOaiIdentifier(string $oaiIdentifier): self
    {
        $this->oaiIdentifier = $oaiIdentifier;

        return $this;
    }

    public function getSetSpec(): string
    {
        return $this->setSpec;
    }

    public function setSetSpec(string $setSpec): self
    {
        $this->setSpec = $setSpec;

        return $this;
    }

    public function getDatestamp(): DateTimeImmutable
    {
        return $this->datestamp;
    }

    public function setDatestamp(DateTimeImmutable $datestamp): self
    {
        $this->datestamp = $datestamp;

        return $this;
    }

    public function getObjectNumber(): ?string
    {
        return $this->objectNumber;
    }

    public function setObjectNumber(?string $objectNumber): self
    {
        $this->objectNumber = $objectNumber;

        return $this;
    }

    public function getOaiXml(): string
    {
        return $this->oaiXml;
    }

    public function setOaiXml(string $oaiXml): self
    {
        $this->oaiXml = $oaiXml;

        return $this;
    }

    public function getMuseumplusXml(): string
    {
        return $this->museumplusXml;
    }

    public function setMuseumplusXml(string $museumplusXml): self
    {
        $this->museumplusXml = $museumplusXml;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
