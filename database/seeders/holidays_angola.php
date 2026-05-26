<?php

/**
 * Seeder: Feriados Nacionais de Angola
 * Fonte: Art.º 225.º LGT Lei n.º 7/15 + Legislação complementar
 *
 * Executado automaticamente após criação de novo tenant
 */

return [
    'feriados_angola' => [
        [
            'nome'       => 'Ano Novo',
            'mes'        => 1,
            'dia'        => 1,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Início da Luta Armada de Libertação Nacional',
            'mes'        => 2,
            'dia'        => 4,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Dia Internacional da Mulher',
            'mes'        => 3,
            'dia'        => 8,
            'tipo'       => 'nacional',
            'meio_dia'   => 1, // Feriado de meio-dia
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Dia da Paz e Reconciliação Nacional',
            'mes'        => 4,
            'dia'        => 4,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Dia Internacional do Trabalhador',
            'mes'        => 5,
            'dia'        => 1,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Dia de África',
            'mes'        => 5,
            'dia'        => 25,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Dia Internacional da Criança',
            'mes'        => 6,
            'dia'        => 1,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Dia do Fundador da Nação e do Herói Nacional',
            'mes'        => 9,
            'dia'        => 17,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Dia de Finados',
            'mes'        => 11,
            'dia'        => 2,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Dia da Independência Nacional',
            'mes'        => 11,
            'dia'        => 11,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
        [
            'nome'       => 'Natal',
            'mes'        => 12,
            'dia'        => 25,
            'tipo'       => 'nacional',
            'meio_dia'   => 0,
            'recorrente' => 1,
        ],
    ],
];
