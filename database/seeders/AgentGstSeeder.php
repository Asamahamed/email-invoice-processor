<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AgentGst;
use Illuminate\Support\Facades\Log;

class AgentGstSeeder extends Seeder
{
    public function run()
    {
        $agents = [
            ['agent_name' => '30 Sundays', 'gst_number' => '07AALCB2200R1ZJ'],
            ['agent_name' => 'Travel Troops Global Private Limited', 'gst_number' => '33AAECT8475B1ZD', 'remarks' => 'Pick your Trail'],
            ['agent_name' => 'Royal Sky Holidays', 'gst_number' => '32AAMCR9854N1ZJ'],
            ['agent_name' => 'Multiple task', 'gst_number' => '30ABTPR1578C1ZL'],
            ['agent_name' => 'Shakthi Vacations Pvt Ltd', 'gst_number' => '27AAUCS4283J1ZK'],
            ['agent_name' => '21st Century Tours', 'gst_number' => '32AEGPM4184F1ZN'],
            ['agent_name' => 'Haptrip', 'gst_number' => '09DIYPS2086F1ZF'],
            ['agent_name' => 'O2 LEISURE HOLIDAYS LLP', 'gst_number' => '27AAIFO0542R1ZS'],
            ['agent_name' => 'HOLIDAY TRIANGLE TRAVEL PRIVATE LIMITED', 'gst_number' => '06AACCH7688E1ZD'],
            ['agent_name' => 'DHARMASASTHA TRAVEL EXPLORER', 'gst_number' => '29FIGPS1191K1ZM'],
            ['agent_name' => 'KSM HOLIDAYS', 'gst_number' => '33BLEPS5155C1ZA'],
            ['agent_name' => 'BREAK BAG', 'gst_number' => '19AAGCB4757N1Z1'],
            ['agent_name' => 'MD TRAVELS', 'gst_number' => '07ABYFM5124E1Z1'],
            ['agent_name' => 'EZI DRIVE TOURS AND TRAVELS PRIVATE LIMITED', 'gst_number' => '29AADCE2730M1ZH'],
            ['agent_name' => 'MILES2FLY', 'gst_number' => '29AAYFM9047R1Z'],
            ['agent_name' => 'Happy Vacations Tours and Travels', 'gst_number' => '37AFCPG1370C1Z1'],
            ['agent_name' => 'Travel n Time', 'gst_number' => '06AAQPJ9800R1ZX'],
            ['agent_name' => 'INFYZONE TECHNOLOGY SERVICES (OPC) PRIVATE LIMITED', 'gst_number' => '33AAGCI9795P1ZK', 'remarks' => 'TRIP BY GENIE'],
            ['agent_name' => 'NIRAJ TOURS AND TRAVELS', 'gst_number' => '27ACCPT4066B1ZQ'],
            ['agent_name' => 'Travique Odyssey LLP', 'gst_number' => '29AAYFT6495Q1ZH'],
            ['agent_name' => 'Go and Enjoy Holidays', 'gst_number' => '37AAXFG2944G1ZX'],
            ['agent_name' => 'HIFLY TRAVEL SOLUTIONS PRIVATE LIMITED', 'gst_number' => '33AADCH3900N1ZP'],
            ['agent_name' => 'GIA Holidays', 'gst_number' => '33AANPE1776A1Z4'],
            ['agent_name' => 'Trip Planners', 'gst_number' => '07ACZPJ7510M1ZX'],
            ['agent_name' => 'Skywings International Travel PVT LTD', 'gst_number' => '06AAXCS5433R1ZB'],
            ['agent_name' => 'Yas Tours and travels', 'gst_number' => '34AADFY0877A1ZD'],
            ['agent_name' => 'Global Voyage', 'gst_number' => '34CLDPS7084Q1Z7'],
            ['agent_name' => 'Planet Holidays', 'gst_number' => '27AMWPS3260N1Z3'],
            ['agent_name' => 'Feel It Holidays', 'gst_number' => '07AFCFS0522GIZD'],
            ['agent_name' => 'AIRLIFT OVERSEAS SERVICES', 'gst_number' => '33AAIFA8441P1Z3'],
            ['agent_name' => 'Fortune Yatra Pvt. Ltd', 'gst_number' => '07AABCF8297Q1ZQ'],
            ['agent_name' => 'Gantu Online Private Limited', 'gst_number' => '06AALCG4733G1ZL'],
            ['agent_name' => 'Jambudvipa Tours', 'gst_number' => '33AGVPK8231G1Z6'],
            ['agent_name' => 'MANGALAM HOLIDAYS INDIA PVT. LTD.', 'gst_number' => '33AAPCM2174H1ZD'],
            ['agent_name' => 'HOLIDAY BREAKS', 'gst_number' => '07AAFCH1246M1ZH'],
            ['agent_name' => 'Travel Berries', 'gst_number' => '33AAICTS333LIZ3'],
            ['agent_name' => 'ARIUS HOLIDAYS', 'gst_number' => '29ABJFA3972F1Z8'],
            ['agent_name' => 'Monsoon Cruise', 'gst_number' => '32ATLPK0066C1Z6'],
            ['agent_name' => 'Trip N Events', 'gst_number' => '33AAQCA2483A1ZY'],
            ['agent_name' => 'U Enjoy Tours', 'gst_number' => '29ALYPC4718L1ZE'],
            ['agent_name' => 'Trade Wings Varanasi', 'gst_number' => '09AABCR1467J1Z6'],
            ['agent_name' => 'Power Tours', 'gst_number' => '33KZKPS7285E1ZO'],
            ['agent_name' => 'Dream Explorer', 'gst_number' => '09AAKFD6478K1ZQ'],
            ['agent_name' => 'Romaturs Travel', 'gst_number' => '32AANCR7949H1ZV'],
            ['agent_name' => 'TRAVOCORP SOLUTIONS PRIVATE LIMITED', 'gst_number' => '33AAJCT7811L1Z1'],
            ['agent_name' => 'DREAM HOLIDAYS', 'gst_number' => '24AEVPN3467P1ZJ'],
            ['agent_name' => 'Desire Holidays Pvt Ltd.', 'gst_number' => '27AQUPP4104E1ZQ'],
            ['agent_name' => 'Arya travels', 'gst_number' => '04AHOPJ4451D1ZL'],
            ['agent_name' => 'Alliance International Tours & Travels', 'gst_number' => '27AAIFA3769N3ZT'],
            ['agent_name' => 'Destination Planner', 'gst_number' => '33AKVPB0238L1Z4'],
            ['agent_name' => 'Navasakthi Tours & Travel,Chennai', 'gst_number' => '33ADYPV4845M1ZH'],
            ['agent_name' => 'Travel Infoline', 'gst_number' => '24BTAPS9843R1ZR'],
            ['agent_name' => 'Benito Therapeutic Pvt Ltd', 'gst_number' => '21AAICB7555N1ZC'],
            ['agent_name' => 'Pyramid Holiday Tours Pvt Ltd', 'gst_number' => '30AZCPS0731K2ZL'],
            ['agent_name' => 'TRAVEL TAJ', 'gst_number' => '07AFAPG4760J1ZJ'],
            ['agent_name' => 'Tripping Cube (HUNTINGCUBE TRAVEL SOLUTION)', 'gst_number' => '09AAFCH1590Q1ZY'],
            ['agent_name' => 'Cherry blossom holidays', 'gst_number' => '09GHOPK9813A1Z2'],
            ['agent_name' => 'Auvjan Tour Planner Ltd.', 'gst_number' => '09AARCA3131A1Z1'],
            ['agent_name' => 'SADAYA TRIPS LLP', 'gst_number' => '08AFHFS4668EIZN'],
        ];

        foreach ($agents as $agent) {
            AgentGst::updateOrCreate(
                ['agent_name' => $agent['agent_name']],  // Check by agent_name
                [
                    'gst_number' => $agent['gst_number'],
                    'remarks' => $agent['remarks'] ?? null,
                ]
            );
        }

        $this->command->info('✅ Agent GST details seeded successfully!');
    }
}