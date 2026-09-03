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
            const RISK_ORDER = ['low', 'medium', 'high'];

            const RISK_META = {
                low: { name: 'Низкий', color: '#22C55E' },
                medium: { name: 'Средний', color: '#EAB308' },
                high: { name: 'Высокий', color: '#EF4444' },
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

                    const categoryIndex = (riskLevel) => RISK_ORDER.indexOf(riskLevel ?? 'low');

                    const nodes = graph.nodes.map((node) => ({
                        id: String(node.clientId),
                        name: node.label,
                        category: categoryIndex(node.riskLevel),
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
                            connectionLabel: edge.connectionLabel,
                            lineStyle: { curveness: 0.1 + pairIndex * 0.15 },
                        };
                    });

                    return {
                        tooltip: {
                            formatter: (params) => {
                                if (params.dataType === 'edge') {
                                    return params.data.connectionLabel + ': ' + params.data.entityLabel;
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
                            force: { repulsion: 650, edgeLength: [160, 260], gravity: 0.08 },
                            edgeLabel: {
                                show: true,
                                formatter: (params) => params.data.connectionLabel,
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
