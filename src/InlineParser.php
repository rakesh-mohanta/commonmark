<?php

/*
 * This file is part of the commonmark-php package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * Original code based on stmd.js
 *  - (c) John MacFarlane
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ColinODell\CommonMark;

use ColinODell\CommonMark\Element\InlineElement;
use ColinODell\CommonMark\Element\InlineElementInterface;
use ColinODell\CommonMark\Element\InlineCreator;
use ColinODell\CommonMark\Reference\Reference;
use ColinODell\CommonMark\Reference\ReferenceMap;
use ColinODell\CommonMark\Util\Html5Entities;
use ColinODell\CommonMark\Util\RegexHelper;
use ColinODell\CommonMark\Util\ArrayCollection;
use ColinODell\CommonMark\Util\UrlEncoder;

/**
 * Parses inline elements
 */
class InlineParser
{
    /**
     * @var string
     */
    protected $subject;

    /**
     * @var int
     */
    protected $labelNestLevel = 0; // Used by parseLinkLabel method

    /**
     * @var int
     */
    protected $pos = 0;

    /**
     * @var ReferenceMap
     */
    protected $refmap;

    /**
     * @var RegexHelper
     */
    protected $regexHelper;

    /**
     * @var array|null
     */
    private $emphasisOpeners;

    /**
     * Constrcutor
     */
    public function __construct()
    {
        $this->refmap = new ReferenceMap();
    }

    /**
     * If re matches at current position in the subject, advance
     * position in subject and return the match; otherwise return null
     * @param string $re
     *
     * @return string|null The match (if found); null otherwise
     */
    protected function match($re)
    {
        $matches = array();
        $subject = substr($this->subject, $this->pos);
        if (!preg_match($re, $subject, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // [0][0] contains the matched text
        // [0][1] contains the index of that match
        $this->pos += $matches[0][1] + strlen($matches[0][0]);

        return $matches[0][0];
    }

    /**
     * Returns the character at the current subject position, or null if
     * there are no more characters
     *
     * @return string|null
     */
    protected function peek()
    {
        $ch = substr($this->subject, $this->pos, 1);

        return false !== $ch && strlen($ch) > 0 ? $ch : null;
    }

    /**
     * Parse zero or more space characters, including at most one newline
     *
     * @return int
     */
    protected function spnl()
    {
        $this->match('/^ *(?:\n *)?/');

        return 1;
    }

    // All of the parsers below try to match something at the current position
    // in the subject.  If they succeed in matching anything, they
    // push an inline element onto the 'inlines' list.  They return the
    // number of characters parsed (possibly 0).

    /**
     * Attempt to parse backticks, adding either a backtick code span or a
     * literal sequence of backticks to the 'inlines' list.
     * @param \ColinODell\CommonMark\Util\ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseBackticks(ArrayCollection $inlines)
    {
        $ticks = $this->match('/^`+/');
        if (!$ticks) {
            return false;
        }

        $afterOpenTicks = $this->pos;
        $foundCode = false;
        $match = null;
        while (!$foundCode && ($match = $this->match('/`+/m'))) {
            if ($match == $ticks) {
                $c = substr($this->subject, $afterOpenTicks, $this->pos - $afterOpenTicks - strlen($ticks));
                $c = preg_replace('/[ \n]+/', ' ', $c);
                $inlines->add(InlineCreator::createCode(trim($c)));

                return true;
            }
        }

        // If we go here, we didn't match a closing backtick sequence
        $this->pos = $afterOpenTicks;
        $inlines->add(InlineCreator::createText($ticks));

        return true;
    }

    /**
     * Parse a backslash-escaped special character, adding either the escaped
     * character, a hard line break (if the backslash is followed by a newline),
     * or a literal backslash to the 'inlines' list.
     *
     * @param \ColinODell\CommonMark\Util\ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseBackslash(ArrayCollection $inlines)
    {
        $subject = $this->subject;
        $pos = $this->pos;
        if ($subject[$pos] !== '\\') {
            return false;
        }

        if (isset($subject[$pos + 1]) && $subject[$pos + 1] === "\n") {
            $this->pos += 2;
            $inlines->add(InlineCreator::createHardbreak());
        } elseif (isset($subject[$pos + 1]) && preg_match(
                '/' . RegexHelper::REGEX_ESCAPABLE . '/',
                $subject[$pos + 1]
            )
        ) {
            $this->pos += 2;
            $inlines->add(InlineCreator::createText($subject[$pos + 1]));
        } else {
            $this->pos++;
            $inlines->add(InlineCreator::createText('\\'));
        }

        return true;
    }

    /**
     * Attempt to parse an autolink (URL or email in pointy brackets)
     * @param \ColinODell\CommonMark\Util\ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseAutolink(ArrayCollection $inlines)
    {
        $emailRegex = '/^<([a-zA-Z0-9.!#$%&\'*+\\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/';
        $otherLinkRegex = '/^<(?:coap|doi|javascript|aaa|aaas|about|acap|cap|cid|crid|data|dav|dict|dns|file|ftp|geo|go|gopher|h323|http|https|iax|icap|im|imap|info|ipp|iris|iris.beep|iris.xpc|iris.xpcs|iris.lwz|ldap|mailto|mid|msrp|msrps|mtqp|mupdate|news|nfs|ni|nih|nntp|opaquelocktoken|pop|pres|rtsp|service|session|shttp|sieve|sip|sips|sms|snmp|soap.beep|soap.beeps|tag|tel|telnet|tftp|thismessage|tn3270|tip|tv|urn|vemmi|ws|wss|xcon|xcon-userid|xmlrpc.beep|xmlrpc.beeps|xmpp|z39.50r|z39.50s|adiumxtra|afp|afs|aim|apt|attachment|aw|beshare|bitcoin|bolo|callto|chrome|chrome-extension|com-eventbrite-attendee|content|cvs|dlna-playsingle|dlna-playcontainer|dtn|dvb|ed2k|facetime|feed|finger|fish|gg|git|gizmoproject|gtalk|hcp|icon|ipn|irc|irc6|ircs|itms|jar|jms|keyparc|lastfm|ldaps|magnet|maps|market|message|mms|ms-help|msnim|mumble|mvn|notes|oid|palm|paparazzi|platform|proxy|psyc|query|res|resource|rmi|rsync|rtmp|secondlife|sftp|sgn|skype|smb|soldat|spotify|ssh|steam|svn|teamspeak|things|udp|unreal|ut2004|ventrilo|view-source|webcal|wtai|wyciwyg|xfire|xri|ymsgr):[^<>\x00-\x20]*>/i';

        if ($m = $this->match($emailRegex)) {
            $email = substr($m, 1, -1);
            $inlines->add(InlineCreator::createLink('mailto:' . UrlEncoder::unescapeAndEncode($email), $email));

            return true;
        } elseif ($m = $this->match($otherLinkRegex)) {
            $dest = substr($m, 1, -1);
            $inlines->add(InlineCreator::createLink(UrlEncoder::unescapeAndEncode($dest), $dest));

            return true;
        } else {
            return false;
        }
    }

    /**
     * Attempt to parse a raw HTML tag
     * @param \ColinODell\CommonMark\Util\ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseHtmlTag(ArrayCollection $inlines)
    {
        if ($m = $this->match(RegexHelper::getInstance()->getHtmlTagRegex())) {
            $inlines->add(InlineCreator::createHtml($m));

            return true;
        }

        return false;
    }

    /**
     * Scan a sequence of characters == c, and return information about
     * the number of delimiters and whether they are positioned such that
     * they can open and/or close emphasis or strong emphasis.  A utility
     * function for strong/emph parsing.
     *
     * @param string $char
     *
     * @return array
     */
    protected function scanDelims($char)
    {
        $numDelims = 0;
        $startPos = $this->pos;

        $charBefore = $this->pos === 0 ? "\n" : $this->subject[$this->pos - 1];

        while ($this->peek() === $char) {
            $numDelims++;
            $this->pos++;
        }

        $charAfter = $this->peek() ? : "\n";

        $canOpen = $numDelims > 0 && !preg_match('/\s/', $charAfter);
        $canClose = $numDelims > 0 && !preg_match('/\s/', $charBefore);
        if ($char === '_') {
            $canOpen = $canOpen && !preg_match('/[a-z0-9]/i', $charBefore);
            $canClose = $canClose && !preg_match('/[a-z0-9]/i', $charAfter);
        }

        $this->pos = $startPos;

        return compact('numDelims', 'canOpen', 'canClose');
    }

    /**
     * @param string          $c
     * @param ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseEmphasis($c, ArrayCollection $inlines)
    {
        $startPos = $this->pos;

        // Get opening delimiters
        $res = $this->scanDelims($c);
        $numDelims = $res['numDelims'];

        if ($numDelims === 0) {
            $this->pos = $startPos;

            return false;
        }

        if ($res['canClose']) {
            $opener = $this->emphasisOpeners;
            while (!empty($opener)) {
                if ($opener['c'] === $c) { // we have a match!
                    if ($numDelims < 3 || $opener['numDelims'] < 3) {
                        $useDelims = $numDelims <= $opener['numDelims'] ? $numDelims : $opener['numDelims'];
                    } else {
                        $useDelims = $numDelims % 2 === 0 ? 2 : 1;
                    }

                    $type = $useDelims === 1 ? InlineElement::TYPE_EMPH : InlineElement::TYPE_STRONG;

                    if ($opener['numDelims'] == $useDelims) { // all openers used
                        $this->pos += $useDelims;
                        $inlines->set($opener['pos'], new InlineElement($type, array('c' => $inlines->slice($opener['pos'] + 1))));
                        $inlines->splice($opener['pos'] + 1, $inlines->count() - ($opener['pos'] + 1));
                        // Remove entries after this, to prevent overlapping nesting:
                        $this->emphasisOpeners = $opener['previous'];

                        return true;
                    } elseif ($opener['numDelims'] > $useDelims) { // only some openers used
                        $this->pos += $useDelims;
                        $opener['numDelims'] -= $useDelims;
                        /** @var InlineElement $thingToChange */
                        $thingToChange = $inlines->get($opener['pos']);
                        $thingToChange->setContents(substr($thingToChange->getContents(), 0, $opener['numDelims']));
                        $inlines->set(
                            $opener['pos'] + 1,
                            new InlineElement($type, array('c' => $inlines->slice($opener['pos'] + 1)))
                        );
                        $inlines->splice($opener['pos'] + 2, $inlines->count() - ($opener['pos'] + 2));
                        // Remove entries after this, to prevent overlapping nesting:
                        $this->emphasisOpeners = $opener;

                        return true;
                    } else {
                        throw new \LogicException('Logic error: usedelims > opener.numdelims');
                    }
                }

                $opener = $opener['previous'];
            }
        }

        // If we're here, we didn't match a closer
        $this->pos += $numDelims;
        $inlines->add(InlineCreator::createText(substr($this->subject, $startPos, $numDelims)));

        if ($res['canOpen']) {
            $this->emphasisOpeners = array(
                'c' => $c,
                'numDelims' => $numDelims,
                'pos' => $inlines->count() - 1,
                'previous' => $this->emphasisOpeners
            );
        }

        return true;
    }

    /**
     * Attempt to parse link title (sans quotes)
     *
     * @return null|string The string, or null if no match
     */
    protected function parseLinkTitle()
    {
        if ($title = $this->match(RegexHelper::getInstance()->getLinkTitleRegex())) {
            // Chop off quotes from title and unescape
            return RegexHelper::unescape(substr($title, 1, strlen($title) - 2));
        } else {
            return null;
        }
    }

    /**
     * Attempt to parse link destination
     *
     * @return null|string The string, or null if no match
     */
    protected function parseLinkDestination()
    {
        if ($res = $this->match(RegexHelper::getInstance()->getLinkDestinationBracesRegex())) {
            // Chop off surrounding <..>:
            return UrlEncoder::unescapeAndEncode(
                RegexHelper::unescape(
                    substr($res, 1, strlen($res) - 2)
                )
            );
        } else {
            $res = $this->match(RegexHelper::getInstance()->getLinkDestinationRegex());
            if ($res !== null) {
                return UrlEncoder::unescapeAndEncode(
                    RegexHelper::unescape($res)
                );
            } else {
                return null;
            }
        }
    }

    /**
     * @return int
     */
    protected function parseLinkLabel()
    {
        if ($this->peek() != '[') {
            return 0;
        }

        $startPos = $this->pos;
        $nestLevel = 0;
        if ($this->labelNestLevel > 0) {
            // If we've already checked to the end of this subject
            // for a label, even with a different starting [, we
            // know we won't find one here and we can just return.
            // This avoids lots of backtracking.
            // Note:  nest level 1 would be: [foo [bar]
            //        nest level 2 would be: [foo [bar [baz]
            $this->labelNestLevel--;

            return 0;
        }

        $this->pos++; // Advance past [
        while (($c = $this->peek()) !== null && ($c != ']' || $nestLevel > 0)) {
            switch ($c) {
                case '`':
                    $this->parseBackticks(new ArrayCollection());
                    break;
                case '<':
                    $this->parseAutolink(new ArrayCollection()) || $this->parseHtmlTag(
                        new ArrayCollection()
                    ) || $this->parseString(new ArrayCollection());
                    break;
                case '[': // nested []
                    $nestLevel++;
                    $this->pos++;
                    break;
                case ']': //nested []
                    $nestLevel--;
                    $this->pos++;
                    break;
                case '\\':
                    $this->parseBackslash(new ArrayCollection());
                    break;
                default:
                    $this->parseString(new ArrayCollection());
            }
        }

        if ($c === ']') {
            $this->labelNestLevel = 0;
            $this->pos++; // advance past ]

            return $this->pos - $startPos;
        } else {
            if ($c === null) {
                $this->labelNestLevel = $nestLevel;
            }

            $this->pos = $startPos;

            return 0;
        }
    }

    /**
     * Parse raw link label, including surrounding [], and return
     * inline contents.
     *
     * @param string $s
     *
     * @return ArrayCollection|InlineElementInterface[] Inline contents
     */
    private function parseRawLabel($s)
    {
        // note:  parse without a refmap; we don't want links to resolve
        // in nested brackets!
        $parser = new self();
        $substring = substr($s, 1, strlen($s) - 2);

        return $parser->parse($substring, new ReferenceMap());
    }

    /**
     * Attempt to parse a link.  If successful, add the link to inlines.
     * @param ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseLink(ArrayCollection $inlines)
    {
        $startPos = $this->pos;
        $n = $this->parseLinkLabel();
        if ($n === 0) {
            return false;
        }

        $rawLabel = substr($this->subject, $startPos, $n);

        // if we got this far, we've parsed a label.
        // Try to parse an explicit link: [label](url "title")
        if ($this->peek() == '(') {
            $this->pos++;
            if ($this->spnl() &&
                (($dest = $this->parseLinkDestination()) !== null) &&
                $this->spnl()
            ) {
                // make sure there's a space before the title:
                if (preg_match('/^\\s/', $this->subject[$this->pos - 1])) {
                    $title = $this->parseLinkTitle() ? : '';
                } else {
                    $title = null;
                }

                if ($this->spnl() && $this->match('/^\\)/')) {
                    $inlines->add(InlineCreator::createLink($dest, $this->parseRawLabel($rawLabel), $title));

                    return $this->pos - $startPos;
                }
            }

            $this->pos = $startPos;

            return false;
        }

        // If we're here, it wasn't an explicit link. Try to parse a reference link.
        // first, see if there's another label
        $savePos = $this->pos;
        $this->spnl();
        $beforeLabel = $this->pos;
        $n = $this->parseLinkLabel();
        if ($n == 2) {
            // empty second label
            $refLabel = $rawLabel;
        } elseif ($n > 0) {
            $refLabel = substr($this->subject, $beforeLabel, $n);
        } else {
            $this->pos = $savePos;
            $refLabel = $rawLabel;
        }

        // Lookup rawLabel in refmap
        if ($link = $this->refmap->getReference($refLabel)) {
            $inlines->add(
                InlineCreator::createLink($link->getDestination(), $this->parseRawLabel($rawLabel), $link->getTitle())
            );

            return true;
        }

        // Nothing worked, rewind:
        $this->pos = $startPos;

        return false;
    }

    /**
     * Attempt to parse an entity, adding to inlines if successful
     * @param \ColinODell\CommonMark\Util\ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseEntity(ArrayCollection $inlines)
    {
        if ($m = $this->match(RegexHelper::REGEX_ENTITY)) {
            $inlines->add(InlineCreator::createText(Html5Entities::decodeEntity($m)));

            return true;
        }

        return false;
    }

    /**
     * Parse a run of ordinary characters, or a single character with
     * a special meaning in markdown, as a plain string, adding to inlines.
     *
     * @param \ColinODell\CommonMark\Util\ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseString(ArrayCollection $inlines)
    {
        if ($m = $this->match(RegexHelper::getInstance()->getMainRegex())) {
            $inlines->add(InlineCreator::createText($m));

            return true;
        }

        return false;
    }

    /**
     * Parse a newline.
     *
     * @param \ColinODell\CommonMark\Util\ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseNewline(ArrayCollection $inlines)
    {
        if ($m = $this->match('/^ *\n/')) {
            if (strlen($m) > 2) {
                $inlines->add(InlineCreator::createHardbreak());
            } elseif (strlen($m) > 0) {
                $inlines->add(InlineCreator::createSoftbreak());
            }

            return true;
        }

        return false;
    }

    /**
     * @param ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseImage(ArrayCollection $inlines)
    {
        if ($this->match('/^!/')) {
            $link = $this->parseLink($inlines);
            if (!$link) {
                $inlines->add(InlineCreator::createText('!'));

                return true;
            }

            /** @var InlineElementInterface $last */
            $last = $inlines->last();

            if ($last && $last->getType() == InlineElement::TYPE_LINK) {
                $last->setType(InlineElement::TYPE_IMAGE);

                return true;
            }
        }

        return false;
    }

    /**
     * Parse the next inline element in subject, advancing subject position
     * and adding the result to 'inlines'.
     *
     * @param \ColinODell\CommonMark\Util\ArrayCollection $inlines
     *
     * @return bool
     */
    protected function parseInline(ArrayCollection $inlines)
    {
        $c = $this->peek();
        if ($c === null) {
            return false;
        }

        $res = null;

        switch ($c) {
            case "\n":
            case ' ':
                $res = $this->parseNewline($inlines);
                break;
            case '\\':
                $res = $this->parseBackslash($inlines);
                break;
            case '`':
                $res = $this->parseBackticks($inlines);
                break;
            case '*':
            case '_':
                $res = $this->parseEmphasis($c, $inlines);
                break;
            case '[':
                $res = $this->parseLink($inlines);
                break;
            case '!':
                $res = $this->parseImage($inlines);
                break;
            case '<':
                $res = $this->parseAutolink($inlines) ? : $this->parseHtmlTag($inlines);
                break;
            case '&':
                $res = $this->parseEntity($inlines);
                break;
            default:
                $res = $this->parseString($inlines);
        }

        if (!$res) {
            $this->pos++;
            $inlines->add(InlineCreator::createText($c));
        }

        return true;
    }

    /**
     * Parse s as a list of inlines, using refmap to resolve references.
     *
     * @param string $s
     * @param ReferenceMap $refMap
     *
     * @return ArrayCollection|InlineElementInterface[]
     */
    protected function parseInlines($s, ReferenceMap $refMap)
    {
        $this->subject = $s;
        $this->pos = 0;
        $this->refmap = $refMap;
        $this->emphasisOpeners = null;
        $inlines = new ArrayCollection();
        while ($this->parseInline($inlines)) {
            ;
        }

        return $inlines;
    }

    /**
     * @param string       $s
     * @param ReferenceMap $refMap
     *
     * @return ArrayCollection|Element\InlineElementInterface[]
     */
    public function parse($s, ReferenceMap $refMap)
    {
        return $this->parseInlines($s, $refMap);
    }

    /**
     * Attempt to parse a link reference, modifying refmap.
     * @param string       $s
     * @param ReferenceMap $refMap
     *
     * @return int
     */
    public function parseReference($s, ReferenceMap $refMap)
    {
        $this->subject = $s;
        $this->pos = 0;
        $startPos = $this->pos;

        // label
        $matchChars = $this->parseLinkLabel();
        if ($matchChars === 0) {
            return 0;
        } else {
            $label = substr($this->subject, 0, $matchChars);
        }

        // colon
        if ($this->peek() === ':') {
            $this->pos++;
        } else {
            $this->pos = $startPos;

            return 0;
        }

        // link url
        $this->spnl();

        $destination = $this->parseLinkDestination();
        if ($destination === null || strlen($destination) === 0) {
            $this->pos = $startPos;

            return 0;
        }

        $beforeTitle = $this->pos;
        $this->spnl();
        $title = $this->parseLinkTitle();
        if ($title === null) {
            $title = '';
            // rewind before spaces
            $this->pos = $beforeTitle;
        }

        // make sure we're at line end:
        if ($this->match('/^ *(?:\n|$)/') === null) {
            $this->pos = $startPos;

            return 0;
        }

        if (!$refMap->contains($label)) {
            $refMap->addReference(new Reference($label, $destination, $title));
        }

        return $this->pos - $startPos;
    }
}
