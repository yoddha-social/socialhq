<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use ZipArchive;

/**
 * The file types a knowledge document may be, decided by content, never by
 * the name alone: the extension must also match what the content is.
 */
enum DocumentType: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';
    case Pptx = 'pptx';
    case Text = 'txt';
    case Markdown = 'md';

    /** What finfo calls an Office file: depends on the libmagic version. */
    private const ZIP_MIMES = [
        'application/zip',
        'application/octet-stream',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * The type of the file at $path, named $fileName, or null when it is
     * none of ours (or its name says something else than its content).
     */
    public static function detect(string $path, string $fileName): ?self
    {
        $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $type = match (true) {
            $mime === 'application/pdf' => self::Pdf,
            in_array($mime, self::ZIP_MIMES, true) => self::officeType($path),
            str_starts_with($mime, 'text/') => in_array($extension, ['md', 'markdown'], true) ? self::Markdown : self::Text,
            default => null,
        };

        return $type !== null && in_array($extension, $type->extensions(), true) ? $type : null;
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Pdf => ['pdf'],
            self::Docx => ['docx'],
            self::Pptx => ['pptx'],
            self::Text, self::Markdown => ['txt', 'text', 'md', 'markdown'],
        };
    }

    /**
     * The MIME type the file is served with.
     */
    public function mime(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Pptx => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            self::Text => 'text/plain; charset=UTF-8',
            self::Markdown => 'text/markdown; charset=UTF-8',
        };
    }

    public static function fromMime(string $mime): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->mime() === $mime) {
                return $type;
            }
        }

        return null;
    }

    /**
     * A Word or PowerPoint file is a zip with its main part at a known name.
     */
    private static function officeType(string $path): ?self
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            return match (true) {
                $zip->locateName('word/document.xml') !== false => self::Docx,
                $zip->locateName('ppt/presentation.xml') !== false => self::Pptx,
                default => null,
            };
        } finally {
            $zip->close();
        }
    }
}
