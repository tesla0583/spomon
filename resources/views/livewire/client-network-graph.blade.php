<div
    wire:ignore
    x-data="networkGraph(@js($this->graph()))"
    x-init="init($el)"
    style="height: 500px;"
></div>

{{--
    Инлайновый скрипт вместо resources/js/*: в проекте нет запущенного Vite-пайплайна
    (node_modules/@vite не подключены, Tailwind тоже идёт через CDN) — ECharts подключён
    CDN-скриптом в resources/views/components/layouts/app.blade.php. Alpine здесь —
    встроенный в Livewire 3 (Livewire сам вызывает Alpine.start() через @livewireScripts),
    поэтому регистрация компонента идёт через 'alpine:init', а не напрямую.
--}}
@once
    <script>
        document.addEventListener('alpine:init', () => {
            const RISK_ORDER = ['none', 'single_case', 'needs_attention', 'repeating_pattern', 'part_of_network'];

            const RISK_META = {
                none: { name: 'нет карточки', color: '#9CA3AF' },
                single_case: { name: 'единичный случай', color: '#22C55E' },
                needs_attention: { name: 'требует внимания', color: '#EAB308' },
                repeating_pattern: { name: 'явный повторяющийся паттерн', color: '#F97316' },
                part_of_network: { name: 'часть более широкой сети', color: '#EF4444' },
            };

            const ENTITY_TYPE_LABELS = {
                organization: 'контрагент-юрлицо',
                bank: 'банк',
                person: 'физлицо',
                address: 'адрес',
                unknown: 'связь',
            };

            Alpine.data('networkGraph', (graph) => ({
                chart: null,

                init(el) {
                    this.chart = echarts.init(el);
                    this.chart.setOption(this.buildOption(graph));

                    const resize = () => this.chart && this.chart.resize();
                    window.addEventListener('resize', resize);
                },

                buildOption(graph) {
                    const categories = RISK_ORDER.map((key) => ({
                        name: RISK_META[key].name,
                        itemStyle: { color: RISK_META[key].color },
                    }));

                    const categoryIndex = (riskLabel) => RISK_ORDER.indexOf(riskLabel ?? 'none');

                    const nodes = graph.nodes.map((node) => ({
                        id: String(node.clientId),
                        name: node.label,
                        category: categoryIndex(node.riskLabel),
                        symbolSize: node.isFocus ? 60 : 36,
                        itemStyle: node.isFocus ? { borderColor: '#1D4ED8', borderWidth: 3 } : undefined,
                        label: { show: true },
                    }));

                    // Несколько общих сущностей между той же парой клиентов — несколько
                    // параллельных рёбер; разная кривизна нужна, иначе ECharts отрисует
                    // их друг на друге.
                    const pairCounts = {};
                    const edges = graph.edges.map((edge) => {
                        const pairKey = edge.fromClientId + '-' + edge.toClientId;
                        const pairIndex = pairCounts[pairKey] ?? 0;
                        pairCounts[pairKey] = pairIndex + 1;

                        return {
                            source: String(edge.fromClientId),
                            target: String(edge.toClientId),
                            entityType: edge.entityType,
                            entityLabel: edge.entityLabel,
                            lineStyle: { curveness: 0.1 + pairIndex * 0.15 },
                        };
                    });

                    return {
                        tooltip: {
                            formatter: (params) => {
                                if (params.dataType === 'edge') {
                                    const typeLabel = ENTITY_TYPE_LABELS[params.data.entityType] ?? 'связь';

                                    return typeLabel + ': ' + params.data.entityLabel;
                                }

                                return params.data.name;
                            },
                        },
                        legend: [{ data: categories.map((category) => category.name) }],
                        series: [{
                            type: 'graph',
                            layout: 'force',
                            roam: true,
                            draggable: true,
                            categories,
                            data: nodes,
                            edges,
                            force: { repulsion: 220, edgeLength: 120 },
                            edgeLabel: {
                                show: true,
                                formatter: (params) => ENTITY_TYPE_LABELS[params.data.entityType] ?? 'связь',
                            },
                            lineStyle: { color: '#9CA3AF' },
                            label: { position: 'right' },
                        }],
                    };
                },
            }));
        });
    </script>
@endonce
