<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class RunBenchmark extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'benchmark:word-count';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test LLM adherence to exact word count instructions';

    /**
     * Available LLM models to test.
     */
    protected array $availableModels = [
        'gpt-4o' => [Provider::OpenAI, 'gpt-4o'],
        'gpt-5-nano' => [Provider::OpenAI, 'gpt-5-nano'],
        'gpt-3.5-turbo' => [Provider::OpenAI, 'gpt-3.5-turbo'],
        'claude-3-5-sonnet' => [Provider::Anthropic, 'claude-3-5-sonnet-20241022'],
        'claude-3-5-haiku' => [Provider::Anthropic, 'claude-3-5-haiku-20241022'],
        'claude-3-opus' => [Provider::Anthropic, 'claude-3-opus-20240229'],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        info('🎯 LLM Word Count Adherence Benchmark');
        info('Testing which models best follow exact word count instructions');

        $models = multiselect(
            label: 'Select models to benchmark:',
            options: array_keys($this->availableModels),
            default: ['gpt-5-nano', 'claude-3-5-haiku'],
            hint: 'Use space to select, enter to confirm'
        );

        $useMatrix = confirm(
            label: 'Use test matrix with multiple word counts?',
            default: true
        );

        $wordCounts = [];
        if ($useMatrix) {
            $wordCounts = $this->getWordCountMatrix();
        } else {
            $targetWordCount = text(
                label: 'Target word count:',
                placeholder: '50',
                default: '50',
                validate: fn (string $value) => match (true) {
                    ! is_numeric($value) => 'Please enter a valid number',
                    (int) $value < 5 => 'Word count must be at least 5',
                    (int) $value > 500 => 'Maximum 500 words allowed',
                    default => null,
                }
            );
            $wordCounts = [(int) $targetWordCount];
        }

        $trialsPerModel = text(
            label: 'Number of trials per model per word count:',
            placeholder: '10',
            default: '10',
            validate: fn (string $value) => match (true) {
                ! is_numeric($value) => 'Please enter a valid number',
                (int) $value < 1 => 'At least 1 trial required',
                (int) $value > 50 => 'Maximum 50 trials allowed',
                default => null,
            }
        );

        $temperature = text(
            label: 'Temperature (0.0-1.0):',
            placeholder: '0.7',
            default: '0.7',
            validate: fn (string $value) => match (true) {
                ! is_numeric($value) => 'Please enter a valid number',
                (float) $value < 0 => 'Temperature must be at least 0',
                (float) $value > 1 => 'Temperature must be at most 1',
                default => null,
            }
        );

        $saveResults = confirm(
            label: 'Save detailed results to file?',
            default: true
        );

        info('Starting benchmark with configuration:');
        info('Models: '.implode(', ', $models));
        info('Word counts: '.implode(', ', $wordCounts));
        info('Trials per model per word count: '.$trialsPerModel);
        info('Temperature: '.$temperature);
        info('Total trials: '.(count($models) * count($wordCounts) * (int) $trialsPerModel));

        $results = $this->runWordCountBenchmark(
            $models,
            $wordCounts,
            (int) $trialsPerModel,
            (float) $temperature
        );

        $this->displayResults($results);

        if ($saveResults) {
            $filename = $this->saveDetailedResults($results);
            info('Results saved to: '.$filename);
        }

        return Command::SUCCESS;
    }

    /**
     * Get word count matrix configuration.
     */
    protected function getWordCountMatrix(): array
    {
        $predefinedSets = [
            'Quick Test (10, 25, 50)' => [10, 25, 50],
            'Standard (10, 25, 50, 100, 200)' => [10, 25, 50, 100, 200],
            'Comprehensive (10, 25, 50, 75, 100, 150, 200, 300)' => [10, 25, 50, 75, 100, 150, 200, 300],
            'Custom' => [],
        ];

        $choice = \Laravel\Prompts\select(
            label: 'Choose word count test matrix:',
            options: array_keys($predefinedSets),
            default: 'Standard (10, 25, 50, 100, 200)'
        );

        if ($choice === 'Custom') {
            $input = text(
                label: 'Enter word counts separated by commas (e.g., 10,25,50,100):',
                placeholder: '10,25,50,100',
                validate: function (string $value) {
                    $counts = array_map('trim', explode(',', $value));
                    foreach ($counts as $count) {
                        if (! is_numeric($count)) {
                            return 'All values must be numbers';
                        }
                        if ((int) $count < 5) {
                            return 'All word counts must be at least 5';
                        }
                        if ((int) $count > 500) {
                            return 'Maximum 500 words allowed';
                        }
                    }

                    return null;
                }
            );

            return array_map(fn ($v) => (int) trim($v), explode(',', $input));
        }

        return $predefinedSets[$choice];
    }

    /**
     * Run word count benchmark for selected models.
     */
    protected function runWordCountBenchmark(array $models, array $wordCounts, int $trials, float $temperature): array
    {
        $results = [];
        $totalTrials = count($models) * count($wordCounts) * $trials;
        $currentTrial = 0;

        $progress = progress(
            label: 'Running benchmark trials',
            steps: $totalTrials
        );

        foreach ($models as $modelKey) {
            [$provider, $model] = $this->availableModels[$modelKey];

            $modelResults = [
                'model' => $modelKey,
                'word_count_results' => [],
                'overall_stats' => [
                    'total_trials' => 0,
                    'total_exact_matches' => 0,
                    'total_deviation' => 0,
                    'overall_accuracy' => 0,
                ],
            ];

            foreach ($wordCounts as $targetWords) {
                $wordCountResults = [
                    'target_words' => $targetWords,
                    'trials' => [],
                    'exact_matches' => 0,
                    'total_deviation' => 0,
                    'min_deviation' => PHP_INT_MAX,
                    'max_deviation' => 0,
                ];

                for ($i = 0; $i < $trials; $i++) {
                    $currentTrial++;
                    $progress->advance();
                    $progress->label("Testing {$modelKey} ({$targetWords} words, trial ".($i + 1)."/{$trials})");

                    try {
                        $topics = [
                            'the importance of technology in education',
                            'the benefits of regular exercise',
                            'the impact of climate change',
                            'the future of artificial intelligence',
                            'the value of continuous learning',
                        ];
                        $topic = $topics[array_rand($topics)];

                        $prompt = "Write exactly {$targetWords} words about {$topic}. Count carefully and ensure your response contains exactly {$targetWords} words, no more and no less.";

                        $response = Prism::text()
                            ->using($provider, $model)
                            ->withPrompt($prompt)
                            ->withMaxTokens($targetWords * 10)
                            ->asText();

                        $text = $response->text;
                        $wordCount = str_word_count($text);
                        $deviation = abs($wordCount - $targetWords);

                        $trial = [
                            'trial_number' => $i + 1,
                            'target_words' => $targetWords,
                            'actual_words' => $wordCount,
                            'deviation' => $deviation,
                            'topic' => $topic,
                            'text' => $text,
                        ];

                        $wordCountResults['trials'][] = $trial;

                        if ($wordCount === $targetWords) {
                            $wordCountResults['exact_matches']++;
                            $modelResults['overall_stats']['total_exact_matches']++;
                        }

                        $wordCountResults['total_deviation'] += $deviation;
                        $wordCountResults['min_deviation'] = min($wordCountResults['min_deviation'], $deviation);
                        $wordCountResults['max_deviation'] = max($wordCountResults['max_deviation'], $deviation);

                        $modelResults['overall_stats']['total_deviation'] += $deviation;
                        $modelResults['overall_stats']['total_trials']++;

                    } catch (\Exception $e) {
                        warning("Error testing {$modelKey} ({$targetWords} words): ".$e->getMessage());
                        $wordCountResults['trials'][] = [
                            'trial_number' => $i + 1,
                            'target_words' => $targetWords,
                            'error' => $e->getMessage(),
                        ];
                        $modelResults['overall_stats']['total_trials']++;
                    }
                }

                $wordCountResults['average_deviation'] = $trials > 0
                    ? round($wordCountResults['total_deviation'] / $trials, 2)
                    : 0;
                $wordCountResults['accuracy_rate'] = $trials > 0
                    ? round(($wordCountResults['exact_matches'] / $trials) * 100, 2)
                    : 0;

                $modelResults['word_count_results'][] = $wordCountResults;
            }

            $modelResults['overall_stats']['overall_accuracy'] = $modelResults['overall_stats']['total_trials'] > 0
                ? round(($modelResults['overall_stats']['total_exact_matches'] / $modelResults['overall_stats']['total_trials']) * 100, 2)
                : 0;
            $modelResults['overall_stats']['average_deviation'] = $modelResults['overall_stats']['total_trials'] > 0
                ? round($modelResults['overall_stats']['total_deviation'] / $modelResults['overall_stats']['total_trials'], 2)
                : 0;

            $results[] = $modelResults;
        }

        $progress->finish();

        return $results;
    }

    /**
     * Display benchmark results.
     */
    protected function displayResults(array $results): void
    {
        info('');
        info('📊 Benchmark Results');
        info('═══════════════════════════════════════════════════════════════');

        // Overall summary table
        info('');
        info('Overall Performance Summary:');
        $overallData = [];
        foreach ($results as $result) {
            $overallData[] = [
                $result['model'],
                $result['overall_stats']['overall_accuracy'].'%',
                $result['overall_stats']['total_exact_matches'].'/'.$result['overall_stats']['total_trials'],
                $result['overall_stats']['average_deviation'],
            ];
        }

        table(
            headers: ['Model', 'Overall Accuracy', 'Exact Matches', 'Avg Deviation'],
            rows: $overallData
        );

        // Detailed breakdown by word count
        info('');
        info('Performance by Word Count:');

        foreach ($results as $result) {
            info('');
            info('Model: '.$result['model']);

            $detailData = [];
            foreach ($result['word_count_results'] as $wordCountResult) {
                $detailData[] = [
                    $wordCountResult['target_words'],
                    $wordCountResult['accuracy_rate'].'%',
                    $wordCountResult['exact_matches'].'/'.count($wordCountResult['trials']),
                    $wordCountResult['average_deviation'],
                    $wordCountResult['min_deviation'],
                    $wordCountResult['max_deviation'],
                ];
            }

            table(
                headers: ['Words', 'Accuracy', 'Exact', 'Avg Dev', 'Min Dev', 'Max Dev'],
                rows: $detailData
            );
        }

        // Best performer
        usort($results, fn ($a, $b) => $b['overall_stats']['overall_accuracy'] <=> $a['overall_stats']['overall_accuracy']);
        $bestModel = $results[0];

        info('');
        info('🏆 Best Overall Performer: '.$bestModel['model']);
        info('   Overall Accuracy: '.$bestModel['overall_stats']['overall_accuracy'].'%');
        info('   Total Exact Matches: '.$bestModel['overall_stats']['total_exact_matches'].' out of '.$bestModel['overall_stats']['total_trials']);
        info('   Average Deviation: '.$bestModel['overall_stats']['average_deviation'].' words');
    }

    /**
     * Save detailed benchmark results.
     */
    protected function saveDetailedResults(array $results): string
    {
        $directory = storage_path('benchmarks');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $timestamp = date('Y-m-d_His');
        $filename = "word_count_benchmark_{$timestamp}.json";
        $filepath = $directory.'/'.$filename;

        $wordCounts = [];
        if (! empty($results[0]['word_count_results'])) {
            foreach ($results[0]['word_count_results'] as $wcr) {
                $wordCounts[] = $wcr['target_words'];
            }
        }

        $data = [
            'timestamp' => now()->toIso8601String(),
            'configuration' => [
                'word_counts_tested' => $wordCounts,
                'models_tested' => array_column($results, 'model'),
                'trials_per_word_count' => ! empty($results[0]['word_count_results'][0]['trials'])
                    ? count($results[0]['word_count_results'][0]['trials'])
                    : 0,
            ],
            'results' => $results,
            'summary' => $this->generateSummary($results),
        ];

        file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $filepath;
    }

    /**
     * Generate summary statistics.
     */
    protected function generateSummary(array $results): array
    {
        $summary = [
            'total_models_tested' => count($results),
            'overall_rankings' => [],
            'word_count_breakdown' => [],
        ];

        // Overall rankings
        usort($results, fn ($a, $b) => $b['overall_stats']['overall_accuracy'] <=> $a['overall_stats']['overall_accuracy']);

        foreach ($results as $index => $result) {
            $summary['overall_rankings'][] = [
                'rank' => $index + 1,
                'model' => $result['model'],
                'overall_accuracy' => $result['overall_stats']['overall_accuracy'],
                'average_deviation' => $result['overall_stats']['average_deviation'],
                'total_exact_matches' => $result['overall_stats']['total_exact_matches'],
                'total_trials' => $result['overall_stats']['total_trials'],
            ];
        }

        // Performance breakdown by word count
        $wordCountPerformance = [];
        foreach ($results as $result) {
            foreach ($result['word_count_results'] as $wcr) {
                $targetWords = $wcr['target_words'];
                if (! isset($wordCountPerformance[$targetWords])) {
                    $wordCountPerformance[$targetWords] = [];
                }
                $wordCountPerformance[$targetWords][] = [
                    'model' => $result['model'],
                    'accuracy_rate' => $wcr['accuracy_rate'],
                    'average_deviation' => $wcr['average_deviation'],
                ];
            }
        }

        foreach ($wordCountPerformance as $wordCount => $performances) {
            usort($performances, fn ($a, $b) => $b['accuracy_rate'] <=> $a['accuracy_rate']);
            $summary['word_count_breakdown'][$wordCount] = [
                'best_model' => $performances[0]['model'],
                'best_accuracy' => $performances[0]['accuracy_rate'],
                'all_models' => $performances,
            ];
        }

        return $summary;
    }
}
