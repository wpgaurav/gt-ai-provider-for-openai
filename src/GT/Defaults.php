<?php
/**
 * Shared default lists used by GT abilities.
 *
 * Everything here is filterable so site owners can customize without editing
 * plugin code.
 *
 * @package WordPress\OpenAiAiProvider\GT
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\GT;

if (!defined('ABSPATH')) {
    return;
}

class Defaults
{
    /**
     * Starting list of AI-slop phrases. Case-insensitive substring matches.
     * Trim or extend via the filter `gt_stop_slop_banned_phrases`.
     *
     * @return string[]
     */
    public static function slopPhrases(): array
    {
        return [
            "in today's fast-paced",
            "in today's world",
            "in today's digital",
            'dive into',
            'dive deep into',
            'buckle up',
            'whether you are',
            "whether you're",
            'leverage the power',
            'harness the power',
            'unlock the power',
            'unleash the power',
            'unleash your',
            'game changer',
            'game-changer',
            'game changing',
            'cutting-edge',
            'state-of-the-art',
            'revolutionize',
            'revolutionary',
            'seamless integration',
            'seamlessly integrate',
            'supercharge',
            'take your',
            'next level',
            'endless possibilities',
            'limitless possibilities',
            'ever-evolving',
            'constantly evolving',
            'rapidly evolving',
            'in the realm of',
            'in the world of',
            'in the digital age',
            'that being said',
            'at the end of the day',
            "it's worth noting",
            "it's important to note",
            'needless to say',
            'without further ado',
            'let us explore',
            "let's explore",
            'embark on a journey',
            'navigate the',
            'pave the way',
            'stand out from the crowd',
            'stand the test of time',
            'boasts',
            'plethora',
            'a myriad of',
            'utilize',
            'leverage',
            'delve into',
            'delving into',
            'elevate your',
            'foster',
            'fosters',
            'fostering',
            'robust',
            'seamless',
            'one-stop shop',
            'meets the eye',
            'the backbone of',
            'thought leadership',
            'thought leader',
            'gold standard',
            'tapestry of',
            'rich tapestry',
            'treasure trove',
            'game plan',
            'move the needle',
            'paradigm shift',
            'tech-savvy',
            'in conclusion',
            'to sum up',
            'all in all',
            'in essence',
            'in summary',
        ];
    }

    /**
     * Default English stopword list used by internal-link suggestions.
     *
     * @return string[]
     */
    public static function stopwords(): array
    {
        return [
            'the','a','an','and','or','but','for','of','in','on','at','by','to','with',
            'is','are','was','were','be','been','being','have','has','had','do','does',
            'did','will','would','could','should','may','might','must','shall','can',
            'this','that','these','those','it','its','as','from','how','what','why','when',
            'where','who','which','not','also','than','there','here','about','more','most',
            'some','such','only','own','same','over','into','through','during','before',
            'after','above','below','between','them','their','very','just','too',
        ];
    }

    /**
     * Default rewrite-for-voice system instruction. Short, actionable, avoids
     * site-specific references so any user can benefit from it.
     */
    public static function voiceInstruction(): string
    {
        return "Rewrite the user's input to be clearer, more direct, and more human. Enforce these rules without exception:\n"
            . "1. Use contractions (don't, won't, it's, you're).\n"
            . "2. No em dashes. Use commas or periods.\n"
            . "3. No AI-slop openers ('in today's...', 'dive into...', 'whether you are...', 'leverage', 'utilize', 'delve into').\n"
            . "4. Answer-first: every H2 section opens with the direct answer in the first 1-2 sentences.\n"
            . "5. Paragraphs are 1-4 sentences. Mix short punchy sentences with longer explanatory ones.\n"
            . "6. Use specific numbers and named entities instead of adjectives. '142ms' not 'fast', 'Semrush' not 'an SEO tool'.\n"
            . "7. American English. Straight quotes only.\n"
            . "8. Keep the user's structure (H2s, lists, code blocks). Only rewrite the prose inside them.\n"
            . "Return the rewritten content only, no preamble, no explanation.";
    }
}
