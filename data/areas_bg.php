<?php
/**
 * Initial geography seed.
 *
 * The 24 administrative districts (райони) of Sofia Municipality are official and stable.
 * The neighborhood → district mapping below comes from general knowledge, so every row is
 * seeded with geo_confidence = 'approx'. Phase 2 imports OSM boundaries and upgrades rows
 * to 'verified' (or corrects them). Some neighborhoods are split between two districts in
 * reality (e.g. Манастирски ливади); they are listed under the district that holds most of them.
 *
 * Format: [name_bg, name_en, children[]]
 */
return [
    ['България', 'Bulgaria', [
        ['София', 'Sofia', [
            ['Лозенец', 'Lozenets', [
                ['Лозенец', 'Lozenets'],
                ['Кръстова вада', 'Krastova vada'],
                ['Хладилника', 'Hladilnika'],
            ]],
            ['Триадица', 'Triaditsa', [
                ['Иван Вазов', 'Ivan Vazov'],
                ['Стрелбище', 'Strelbishte'],
                ['Гоце Делчев', 'Gotse Delchev'],
                ['Манастирски ливади', 'Manastirski livadi'],
                ['Южен парк', 'Yuzhen park'],
            ]],
            ['Средец', 'Sredets', [
                ['Център (Средец)', 'Center (Sredets)'],
            ]],
            ['Оборище', 'Oborishte', [
                ['Оборище', 'Oborishte'],
                ['Докторски паметник', 'Doktorski pametnik'],
            ]],
            ['Красно село', 'Krasno selo', [
                ['Хиподрума', 'Hipodruma'],
                ['Борово', 'Borovo'],
                ['Лагера', 'Lagera'],
                ['Бели брези', 'Beli brezi'],
                ['Красно село', 'Krasno selo'],
            ]],
            ['Възраждане', 'Vazrazhdane', [
                ['Зона Б-5', 'Zona B-5'],
                ['Зона Б-18', 'Zona B-18'],
                ['Зона Б-19', 'Zona B-19'],
            ]],
            ['Сердика', 'Serdika', [
                ['Банишора', 'Banishora'],
                ['Орландовци', 'Orlandovtsi'],
            ]],
            ['Подуяне', 'Poduyane', [
                ['Подуяне', 'Poduyane'],
                ['Хаджи Димитър', 'Hadzhi Dimitar'],
                ['Сухата река', 'Suhata reka'],
            ]],
            ['Слатина', 'Slatina', [
                ['Гео Милев', 'Geo Milev'],
                ['Редута', 'Reduta'],
                ['Слатина', 'Slatina'],
            ]],
            ['Изгрев', 'Izgrev', [
                ['Изгрев', 'Izgrev'],
                ['Яворов', 'Yavorov'],
                ['Дианабад', 'Dianabad'],
            ]],
            ['Младост', 'Mladost', [
                ['Младост 1', 'Mladost 1'],
                ['Младост 1А', 'Mladost 1A'],
                ['Младост 2', 'Mladost 2'],
                ['Младост 3', 'Mladost 3'],
                ['Младост 4', 'Mladost 4'],
                ['Горубляне', 'Gorublyane'],
            ]],
            ['Студентски', 'Studentski', [
                ['Студентски град', 'Studentski grad'],
                ['Дървеница', 'Darvenitsa'],
                ['Мусагеница', 'Musagenitsa'],
            ]],
            ['Искър', 'Iskar', [
                ['Дружба 1', 'Druzhba 1'],
                ['Дружба 2', 'Druzhba 2'],
            ]],
            ['Витоша', 'Vitosha', [
                ['Бояна', 'Boyana'],
                ['Драгалевци', 'Dragalevtsi'],
                ['Симеоново', 'Simeonovo'],
                ['Княжево', 'Knyazhevo'],
            ]],
            ['Красна поляна', 'Krasna polyana', [
                ['Красна поляна', 'Krasna polyana'],
                ['Факултета', 'Fakulteta'],
                ['Разсадника', 'Razsadnika'],
            ]],
            ['Илинден', 'Ilinden', [
                ['Илинден', 'Ilinden'],
                ['Захарна фабрика', 'Zaharna fabrika'],
                ['Света Троица', 'Sveta Troitsa'],
            ]],
            ['Надежда', 'Nadezhda', [
                ['Надежда 1', 'Nadezhda 1'],
                ['Надежда 2', 'Nadezhda 2'],
                ['Надежда 3', 'Nadezhda 3'],
                ['Надежда 4', 'Nadezhda 4'],
                ['Свобода', 'Svoboda'],
                ['Толстой', 'Tolstoy'],
            ]],
            ['Овча купел', 'Ovcha kupel', [
                ['Овча купел', 'Ovcha kupel'],
                ['Горна баня', 'Gorna banya'],
            ]],
            ['Люлин', 'Lyulin', [
                ['Люлин 1', 'Lyulin 1'], ['Люлин 2', 'Lyulin 2'], ['Люлин 3', 'Lyulin 3'],
                ['Люлин 4', 'Lyulin 4'], ['Люлин 5', 'Lyulin 5'], ['Люлин 6', 'Lyulin 6'],
                ['Люлин 7', 'Lyulin 7'], ['Люлин 8', 'Lyulin 8'], ['Люлин 9', 'Lyulin 9'],
                ['Люлин 10', 'Lyulin 10'],
            ]],
            ['Връбница', 'Vrabnitsa', [
                ['Обеля', 'Obelya'],
                ['Връбница', 'Vrabnitsa'],
                ['Модерно предградие', 'Moderno predgradie'],
            ]],
            ['Нови Искър', 'Novi Iskar', []],
            ['Кремиковци', 'Kremikovtsi', []],
            ['Панчарево', 'Pancharevo', []],
            ['Банкя', 'Bankya', []],
        ]],
        ['Пловдив', 'Plovdiv', []],
        ['Варна', 'Varna', []],
        ['Бургас', 'Burgas', []],
    ]],
];
