<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm;

use App\DTOs\LlmClientAnalysisRequestDto;
use App\Exceptions\ClaudeApiException;
use App\Services\Llm\ClaudeApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ClaudeApiClientTest extends TestCase
{
    private ClaudeApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key' => 'test-api-key',
            'services.anthropic.model' => 'claude-sonnet-5',
        ]);

        $this->client = new ClaudeApiClient;
    }

    public function test_successful_tool_use_response_is_parsed_into_dto(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_test',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'name' => 'submit_client_analysis',
                        'input' => [
                            'summary' => 'Клиент неоднократно дробил суммы переводов.',
                            'pattern_notes' => 'Повторяющийся паттерн: дробление сумм.',
                            'extracted_entities' => [
                                ['spo_date' => '2026-01-10', 'entities' => ['ООО Ромашка']],
                            ],
                            'network_signal' => [
                                'found' => true,
                                'matched_client_reference' => '42',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->analyzeClient(new LlmClientAnalysisRequestDto(
            clientId: 1,
            spoHistory: [
                ['date' => '2026-01-10', 'amount' => 1000.0, 'currency' => 'TJS', 'description' => 'текст', 'counterparty_structured' => null],
            ],
            knownNetworkEntities: [],
        ));

        self::assertSame('Клиент неоднократно дробил суммы переводов.', $result->summary);
        self::assertSame('Повторяющийся паттерн: дробление сумм.', $result->patternNotes);
        self::assertSame([['spo_date' => '2026-01-10', 'entities' => ['ООО Ромашка']]], $result->extractedEntities);
        self::assertTrue($result->networkSignalFound);
        self::assertSame('42', $result->networkSignalClientReference);
        self::assertSame('msg_test', $result->rawResponse['id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-api-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['tool_choice'] === ['type' => 'tool', 'name' => 'submit_client_analysis'];
        });
    }

    public function test_fields_leaked_into_summary_as_xml_tags_are_recovered(): void
    {
        // Регрессия на реальный ответ, полученный вживую 27.08.2026 от claude-sonnet-5:
        // известный сбой формата модели (не наша ошибка парсинга, см. докблок
        // recoverFieldsLeakedIntoSummary()) — pattern_notes/extracted_entities/
        // network_signal "утекли" как псевдо-XML внутрь summary, последний блок не
        // закрыт тегом </parameter>.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_test',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'name' => 'submit_client_analysis',
                        'input' => [
                            'summary' => 'По клиенту зафиксирован один СПО на сумму 23 000 TJS.</summary>'
                                ."\n".'<parameter name="pattern_notes">История содержит только один СПО, паттерн пока не подтверждён.</parameter>'
                                ."\n".'<parameter name="extracted_entities">[{"spo_date":"2026-05-28","entities":["Алиф Банк","ТКХ Матин"]}]</parameter>'
                                ."\n".'<parameter name="network_signal">{"found":false,"matched_client_reference":null}',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->analyzeClient(new LlmClientAnalysisRequestDto(
            clientId: 1,
            spoHistory: [],
            knownNetworkEntities: [],
        ));

        self::assertSame('По клиенту зафиксирован один СПО на сумму 23 000 TJS.', $result->summary);
        self::assertSame('История содержит только один СПО, паттерн пока не подтверждён.', $result->patternNotes);
        self::assertSame(
            [['spo_date' => '2026-05-28', 'entities' => ['Алиф Банк', 'ТКХ Матин']]],
            $result->extractedEntities,
        );
        self::assertFalse($result->networkSignalFound);
        self::assertNull($result->networkSignalClientReference);
    }

    public function test_http_error_response_throws_claude_api_exception(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401),
        ]);

        $this->expectException(ClaudeApiException::class);

        $this->client->analyzeClient(new LlmClientAnalysisRequestDto(
            clientId: 1,
            spoHistory: [],
            knownNetworkEntities: [],
        ));
    }

    public function test_response_without_tool_use_block_throws_claude_api_exception(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_test',
                'content' => [
                    ['type' => 'text', 'text' => 'Обычный текстовый ответ вместо tool_use.'],
                ],
            ], 200),
        ]);

        $this->expectException(ClaudeApiException::class);

        $this->client->analyzeClient(new LlmClientAnalysisRequestDto(
            clientId: 1,
            spoHistory: [],
            knownNetworkEntities: [],
        ));
    }
}
