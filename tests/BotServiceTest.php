<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\BotService;

/**
 * Covers the pure helpers of BotService (prompt building, typing delay,
 * reply sanitising). The queue/database paths need Redis + PostgreSQL and are
 * exercised through the bot-worker instead.
 */
class BotServiceTest extends TestCase
{
    // ------------------------------------------------------------------
    // System prompt
    // ------------------------------------------------------------------

    public function testBuildSystemPromptUsesProfileFields(): void
    {
        $prompt = BotService::buildSystemPrompt([
            'nickname' => 'Maria',
            'age' => 27,
            'sex' => 'female',
        ]);

        $this->assertStringContainsString('Είσαι η Maria, 27 ετών.', $prompt);
        $this->assertStringContainsString('1-on-1 chat', $prompt);
        $this->assertStringContainsString('greeklish', $prompt);
        $this->assertStringContainsString('ΜΗΝ αποκαλύψεις ποτέ ότι είσαι AI', $prompt);
        $this->assertStringContainsString('μην κανονίζεις συναντήσεις από κοντά', $prompt);
    }

    public function testBuildSystemPromptUsesMasculineArticle(): void
    {
        $prompt = BotService::buildSystemPrompt([
            'nickname' => 'Nikos',
            'age' => 31,
            'sex' => 'male',
        ]);

        $this->assertStringContainsString('Είσαι ο Nikos, 31 ετών.', $prompt);
    }

    public function testBuildSystemPromptWithoutAgeOrSex(): void
    {
        $prompt = BotService::buildSystemPrompt(['nickname' => 'Alex']);

        $this->assertStringContainsString('Είσαι ο/η Alex.', $prompt);
        $this->assertStringNotContainsString('ετών', $prompt);
    }

    public function testBuildSystemPromptAppendsPersona(): void
    {
        $prompt = BotService::buildSystemPrompt([
            'nickname' => 'Maria',
            'age' => 27,
            'sex' => 'female',
            'bot_persona' => 'Ακούει ροκ και δουλεύει σε καφετέρια.',
        ]);

        $this->assertStringContainsString('Είσαι η Maria', $prompt);
        $this->assertStringContainsString('Ακούει ροκ και δουλεύει σε καφετέρια.', $prompt);
    }

    public function testCustomPromptReplacesGeneratedOne(): void
    {
        $prompt = BotService::buildSystemPrompt([
            'nickname' => 'Maria',
            'age' => 27,
            'sex' => 'female',
            'bot_custom_prompt' => 'You are a pirate. Speak only in riddles.',
        ]);

        $this->assertStringContainsString('You are a pirate.', $prompt);
        $this->assertStringNotContainsString('Είσαι η Maria', $prompt);
        // The output-format guardrail must survive a custom prompt.
        $this->assertStringContainsString('χωρίς εισαγωγικά', $prompt);
    }

    // ------------------------------------------------------------------
    // Conversation context
    // ------------------------------------------------------------------

    public function testPromptExplainsThatFreeMeansSingle(): void
    {
        // "είσαι ελεύθερη;" on a dating site asks about relationship status; the
        // bot used to answer about having time to chat.
        $prompt = BotService::buildSystemPrompt(['nickname' => 'Maria', 'sex' => 'female']);

        $this->assertStringContainsString('είσαι ελεύθερη;', $prompt);
        $this->assertStringContainsString('αν είσαι σε σχέση', $prompt);
        $this->assertStringContainsString('ΟΧΙ αν έχεις χρόνο για κουβέντα', $prompt);
    }

    public function testPromptCoversTheOtherCommonOpeners(): void
    {
        $prompt = BotService::buildSystemPrompt(['nickname' => 'Maria']);

        foreach (['τι κάνεις;', 'από πού είσαι;', 'ασλ', 'τι ψάχνεις;'] as $phrase) {
            $this->assertStringContainsString($phrase, $prompt, "{$phrase} is not explained");
        }
    }

    public function testPromptTellsTheBotHowToRefusePhotosAndCamera(): void
    {
        $prompt = BotService::buildSystemPrompt(['nickname' => 'Maria']);

        $this->assertStringContainsString('φωτογραφία', $prompt);
        $this->assertStringContainsString('κάμερα', $prompt);
        // It must not out itself as software when refusing.
        $this->assertStringContainsString('Μην πεις ποτέ ότι δεν μπορείς τεχνικά', $prompt);
    }

    public function testContextComesBeforeThePersona(): void
    {
        $prompt = BotService::buildSystemPrompt(['nickname' => 'Maria', 'sex' => 'female', 'age' => 27]);

        $this->assertLessThan(
            strpos($prompt, 'Είσαι η Maria'),
            strpos($prompt, 'ΠΛΑΙΣΙΟ:'),
            'the environment context should frame the persona that follows'
        );
    }

    public function testContextAlsoAppliesToACustomPrompt(): void
    {
        // The context describes the room, not the character, so a custom persona
        // must not lose it.
        $prompt = BotService::buildSystemPrompt([
            'nickname' => 'Maria',
            'bot_custom_prompt' => 'You are a pirate.',
        ]);

        $this->assertStringContainsString('ΠΛΑΙΣΙΟ:', $prompt);
        $this->assertStringContainsString('You are a pirate.', $prompt);
    }

    public function testAConfiguredContextReplacesTheBuiltInOne(): void
    {
        // The panel prefills the field with the built-in text, so an edited
        // value is a full replacement rather than an addition.
        $prompt = BotService::buildSystemPrompt(
            ['nickname' => 'Maria'],
            'ΠΛΑΙΣΙΟ: chat ραδιοφωνικής εκπομπής.'
        );

        $this->assertStringContainsString('ΠΛΑΙΣΙΟ: chat ραδιοφωνικής εκπομπής.', $prompt);
        $this->assertStringNotContainsString('chat γνωριμιών', $prompt);
        $this->assertStringContainsString('Είσαι ο/η Maria.', $prompt);
    }

    public function testAnEmptyContextFallsBackToTheBuiltInOne(): void
    {
        foreach (['', '   '] as $empty) {
            $prompt = BotService::buildSystemPrompt(['nickname' => 'Maria'], $empty);

            $this->assertStringContainsString('ΠΛΑΙΣΙΟ:', $prompt);
            $this->assertStringContainsString('αν είσαι σε σχέση', $prompt);
        }
    }

    // ------------------------------------------------------------------
    // Who the bot is talking to
    // ------------------------------------------------------------------

    public function testPeerFactsAreStatedSoTheyAreNotAskedAgain(): void
    {
        $block = BotService::describePeer([
            'username' => 'nikos',
            'age' => '31',
            'sex' => 'male',
            'location' => 'Θεσσαλονίκη',
        ]);

        $this->assertStringContainsString('nikos', $block);
        $this->assertStringContainsString('31 ετών', $block);
        $this->assertStringContainsString('άντρας', $block);
        $this->assertStringContainsString('Θεσσαλονίκη', $block);
        $this->assertStringContainsString('μην τα ξαναρωτήσεις', $block);
    }

    public function testAPeerWithoutAProfileIsNotInvented(): void
    {
        $block = BotService::describePeer(['username' => 'guest42']);

        $this->assertStringContainsString('guest42', $block);
        $this->assertStringContainsString('μη τα υποθέσεις', $block);
        $this->assertStringNotContainsString('ετών', $block);
    }

    public function testAnEmptyPeerAddsNothing(): void
    {
        $this->assertSame('', BotService::describePeer([]));
    }

    public function testAStaleThreadIsFlagged(): void
    {
        $note = BotService::staleThreadNote(['seconds_since_last_message' => 3 * 86400]);

        $this->assertStringContainsString('3 μέρες', $note);
        $this->assertStringContainsString('Μη συνεχίσεις σαν να μιλούσατε μόλις τώρα', $note);
    }

    public function testAFreshThreadIsNotFlagged(): void
    {
        // A reply a few minutes later needs no explanation.
        $this->assertSame('', BotService::staleThreadNote(['seconds_since_last_message' => 120]));
        $this->assertSame('', BotService::staleThreadNote([]));
    }

    public function testTheStaleNoteStaysOutOfTheCachedPrompt(): void
    {
        // The provider caches identical prompt prefixes; a value that changes
        // every minute would invalidate that on every single reply.
        $prompt = BotService::buildSystemPrompt(
            ['nickname' => 'Maria'],
            '',
            ['username' => 'nikos', 'seconds_since_last_message' => 5 * 86400]
        );

        $this->assertStringNotContainsString('5 μέρες', $prompt);
        $this->assertStringContainsString('nikos', $prompt);
    }

    public function testGapWording(): void
    {
        $this->assertSame('5 λεπτά', BotService::describeGap(300));
        $this->assertSame('1 ώρα', BotService::describeGap(3600));
        $this->assertSame('5 ώρες', BotService::describeGap(5 * 3600));
        $this->assertSame('1 μέρα', BotService::describeGap(86400));
        $this->assertSame('4 μέρες', BotService::describeGap(4 * 86400));
    }

    public function testPeerFactsAreAppendedToThePrompt(): void
    {
        $prompt = BotService::buildSystemPrompt(
            ['nickname' => 'Maria', 'sex' => 'female', 'age' => 27],
            '',
            ['username' => 'nikos', 'age' => '31', 'sex' => 'male']
        );

        $this->assertStringContainsString('Είσαι η Maria', $prompt);
        $this->assertStringContainsString('Μιλάς με τον/την "nikos"', $prompt);
    }

    public function testThePromptPinsTheGrammaticalGender(): void
    {
        // The model answered "elentheros" (masculine) for a female persona.
        $this->assertStringContainsString(
            'θηλυκό γένος',
            BotService::buildSystemPrompt(['nickname' => 'Maria', 'sex' => 'female'])
        );
        $this->assertStringContainsString(
            'αρσενικό γένος',
            BotService::buildSystemPrompt(['nickname' => 'Nikos', 'sex' => 'male'])
        );
    }

    public function testTyposMustStillReadAsGreek(): void
    {
        $prompt = BotService::buildSystemPrompt(['nickname' => 'Maria']);

        $this->assertStringContainsString('να βγάζει πάντα νόημα', $prompt);
    }

    // ------------------------------------------------------------------
    // Typing delay
    // ------------------------------------------------------------------

    public function testTypingDelayScalesWithWordCount(): void
    {
        // 6 words * 1.5s = 9s
        $delay = BotService::calculateTypingDelay('ti kaneis re su ola kala', 1.5, 2, 45);

        $this->assertSame(9, $delay);
    }

    public function testTypingDelayRespectsMinimum(): void
    {
        $this->assertSame(3, BotService::calculateTypingDelay('ok', 1.5, 3, 45));
    }

    public function testTypingDelayRespectsMaximum(): void
    {
        $longText = implode(' ', array_fill(0, 200, 'word'));

        $this->assertSame(20, BotService::calculateTypingDelay($longText, 1.5, 2, 20));
    }

    public function testTypingDelayHandlesExtraWhitespace(): void
    {
        // 3 words regardless of the whitespace runs
        $this->assertSame(6, BotService::calculateTypingDelay("  a \n b   c  ", 2.0, 1, 60));
    }

    public function testTypingDelayClampsInvertedBounds(): void
    {
        $this->assertSame(10, BotService::calculateTypingDelay('a b c', 1.5, 10, 5));
    }

    // ------------------------------------------------------------------
    // Reply sanitising
    // ------------------------------------------------------------------

    public function testSanitizeReplyStripsWrappingQuotes(): void
    {
        $this->assertSame('kalaa esy?', BotService::sanitizeReply('"kalaa esy?"'));
    }

    public function testSanitizeReplyStripsNamePrefix(): void
    {
        $this->assertSame('ti kaneis?', BotService::sanitizeReply('Maria: ti kaneis?'));
    }

    public function testSanitizeReplyDropsReasoningBlocksWithTheirContents(): void
    {
        $this->assertSame('ok re', BotService::sanitizeReply('<thinking>hmm let me think</thinking>ok re'));
        $this->assertSame('ok re', BotService::sanitizeReply("<think>\nplan\n</think>\nok re"));
    }

    public function testSanitizeReplyStripsStrayMarkupButKeepsText(): void
    {
        $this->assertSame('ok re', BotService::sanitizeReply('<b>ok</b> re'));
    }

    public function testSanitizeReplyCollapsesBlankLines(): void
    {
        $this->assertSame("ok\nre", BotService::sanitizeReply("ok\n\n\nre"));
    }

    public function testSanitizeReplyEnforcesMaxLength(): void
    {
        $reply = BotService::sanitizeReply(str_repeat('a', 600), 500);

        $this->assertSame(500, mb_strlen($reply));
    }

    public function testSanitizeReplyKeepsGreekText(): void
    {
        $text = 'καλά, εσύ τι κάνεις;';

        $this->assertSame($text, BotService::sanitizeReply($text));
    }

    public function testSanitizeReplyReturnsEmptyStringForBlankInput(): void
    {
        $this->assertSame('', BotService::sanitizeReply("   \n  "));
    }

    // ------------------------------------------------------------------
    // Goodbye variants
    // ------------------------------------------------------------------

    public function testSplitVariantsTrimsAndDropsBlankLines(): void
    {
        $variants = BotService::splitVariants("  first \n\n\t\n second\n");

        $this->assertSame(['first', 'second'], $variants);
    }

    public function testSplitVariantsHandlesEmptyInput(): void
    {
        $this->assertSame([], BotService::splitVariants("  \n \n"));
    }

    public function testDefaultFarewellsProvideEnoughVariety(): void
    {
        $variants = BotService::splitVariants(BotService::DEFAULT_FAREWELLS);

        // A single canned goodbye would make every bot recognisable.
        $this->assertGreaterThanOrEqual(50, count($variants));
        $this->assertSame($variants, array_unique($variants), 'Default goodbyes must be unique');

        foreach ($variants as $variant) {
            $this->assertNotSame('', trim($variant));
            $this->assertLessThanOrEqual(80, mb_strlen($variant), "Goodbye too long: {$variant}");
        }
    }

    public function testFarewellPromptForbidsQuestionsAndAppointments(): void
    {
        // The closing message is LLM-generated, so the directive must stop it
        // from re-opening the conversation.
        $this->assertStringContainsString('ΤΕΛΕΥΤΑΙΟ', BotService::DEFAULT_FAREWELL_PROMPT);
        $this->assertStringContainsString('ΜΗΝ κάνεις καμία ερώτηση', BotService::DEFAULT_FAREWELL_PROMPT);
    }
}
