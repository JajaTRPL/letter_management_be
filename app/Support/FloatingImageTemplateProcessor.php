<?php

namespace App\Support;

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * TemplateProcessor that can insert an image as a floating (no-wrap) picture
 * instead of an inline one.
 *
 * Used for the paraf next to the Kadep NIP line: an inline 24px-tall paraf
 * grows the NIP line and leaves a visible gap between the name/gelar and the
 * NIP. Floating it keeps the line height untouched and lets the paraf
 * straddle the last gelar line and the NIP line.
 *
 * The positioning lives here rather than in the .docx template on purpose:
 * templates are fetched through Google Docs' docx export, which relocates
 * floating objects, but keeps a plain ${placeholder} intact.
 */
class FloatingImageTemplateProcessor extends TemplateProcessor
{
    private const PICT_START = '<w:pict><v:shape type="#_x0000_t75" style="';

    private const EMU_PER_PX = 9525;

    private const EMU_PER_PT = 12700;

    private int $floatingDrawingId = 9000;

    /**
     * Same arguments as setImageValue() for a single placeholder, plus the
     * offset of the picture relative to where the placeholder was:
     * horizontally from that character, vertically from the top of its line
     * (negative = up).
     *
     * @param mixed $replace see TemplateProcessor::setImageValue()
     */
    public function setFloatingImageValue(
        string $search,
        mixed $replace,
        float $offsetTopPt = -9.0,
        float $offsetLeftPt = 0.0,
    ): void {
        $macro = static::ensureMacroCompleted($search);

        // One occurrence at a time, so each inserted picture can be located
        // right where its placeholder used to be.
        for ($guard = 0; $guard < 50; $guard++) {
            $macroPos = strpos($this->tempDocumentMainPart, $macro);
            if ($macroPos === false) {
                return;
            }

            // setImageValue() leaves everything before the element holding the
            // macro untouched, so the new picture is the first one from there.
            $searchFrom = strrpos(substr($this->tempDocumentMainPart, 0, $macroPos), '<') ?: 0;

            parent::setImageValue($search, $replace, 1);

            if (strpos($this->tempDocumentMainPart, $macro) === $macroPos) {
                return; // not replaced (unusual markup): keep the document as is
            }

            $pictStart = strpos($this->tempDocumentMainPart, self::PICT_START, $searchFrom);
            $pictEnd = $pictStart === false ? false : strpos($this->tempDocumentMainPart, '</w:pict>', $pictStart);
            if ($pictStart === false || $pictEnd === false) {
                return; // keep the inline picture setImageValue() produced
            }
            $pictEnd += strlen('</w:pict>');

            $drawing = $this->floatingDrawing(
                substr($this->tempDocumentMainPart, $pictStart, $pictEnd - $pictStart),
                $offsetTopPt,
                $offsetLeftPt,
            );
            if ($drawing === null) {
                return;
            }

            $this->tempDocumentMainPart = substr_replace(
                $this->tempDocumentMainPart,
                $drawing,
                $pictStart,
                $pictEnd - $pictStart,
            );
        }
    }

    /**
     * Rebuilds PhpWord's inline VML picture as an anchored DrawingML picture
     * positioned relative to its character and line, without text wrapping.
     */
    private function floatingDrawing(string $pict, float $offsetTopPt, float $offsetLeftPt): ?string
    {
        if (
            !preg_match('/style="width:([\d.]+)px;height:([\d.]+)px"/', $pict, $size)
            || !preg_match('/<v:imagedata r:id="([^"]+)"/', $pict, $rel)
        ) {
            return null;
        }

        $cx = (int) round((float) $size[1] * self::EMU_PER_PX);
        $cy = (int) round((float) $size[2] * self::EMU_PER_PX);
        $x = (int) round($offsetLeftPt * self::EMU_PER_PT);
        $y = (int) round($offsetTopPt * self::EMU_PER_PT);
        $id = ++$this->floatingDrawingId;
        $rid = htmlspecialchars($rel[1], ENT_XML1);

        return '<w:drawing>'
            . '<wp:anchor xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251659264"'
            . ' behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1">'
            . '<wp:simplePos x="0" y="0"/>'
            . '<wp:positionH relativeFrom="character"><wp:posOffset>' . $x . '</wp:posOffset></wp:positionH>'
            . '<wp:positionV relativeFrom="line"><wp:posOffset>' . $y . '</wp:posOffset></wp:positionV>'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:wrapNone/>'
            . '<wp:docPr id="' . $id . '" name="Paraf ' . $id . '"/>'
            . '<wp:cNvGraphicFramePr/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="' . $id . '" name="paraf"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $rid . '"/>'
            . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic>'
            . '</wp:anchor>'
            . '</w:drawing>';
    }
}
