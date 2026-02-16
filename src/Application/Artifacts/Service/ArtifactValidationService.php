<?php

declare(strict_types=1);

namespace App\Application\Artifacts\Service;

use App\Application\Artifacts\Contract\ArtifactFormatHandlerInterface;
use App\Domain\Model\ArtifactDescriptor;
use App\Domain\Model\ValidationResult;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ArtifactValidationService
{
    /** @var list<ArtifactFormatHandlerInterface> */
    private array $handlers;

    /**
     * @param iterable<ArtifactFormatHandlerInterface> $handlers
     */
    public function __construct(
        #[AutowireIterator('app.artifacts.format_handler')]
        iterable $handlers,
    ) {
        $this->handlers = array_values(iterator_to_array($handlers));
    }

    /**
     * @param list<ArtifactDescriptor> $artifacts
     * @return list<ValidationResult>
     */
    public function validateMany(array $artifacts): array
    {
        $results = [];

        foreach ($artifacts as $artifact) {
            $results[] = $this->validateSingle($artifact);
        }

        return $results;
    }

    public function validateSingle(ArtifactDescriptor $descriptor): ValidationResult
    {
        $errors = [];

        if ($descriptor->path === '') {
            return new ValidationResult(path: $descriptor->path, ok: false, errors: ['path is required']);
        }

        if (!is_file($descriptor->path)) {
            return new ValidationResult(path: $descriptor->path, ok: false, errors: ['artifact file not found']);
        }

        foreach ($this->handlers as $handler) {
            $result = $handler->validate($descriptor);
            if ($result->ok) {
                return $result;
            }

            if ($result->errors !== []) {
                $errors[] = sprintf('%s: %s', $handler->formatType(), implode('; ', $result->errors));
            }
        }

        if ($errors === []) {
            $errors[] = 'unsupported artifact format';
        }

        return new ValidationResult(path: $descriptor->path, ok: false, errors: $errors);
    }

    public function resolveParser(ValidationResult $validation): ?ArtifactFormatHandlerInterface
    {
        if ($validation->detectedType === null) {
            return null;
        }

        foreach ($this->handlers as $handler) {
            if ($handler->formatType() === $validation->detectedType) {
                return $handler;
            }
        }

        return null;
    }
}
