<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http, App\Models\Contact;

class FpGhlSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fpghl:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncing FirstPromoter lead with GHL';

    private string $fpAccountId = "oocpadac";
    private string $fpApiKey = "30a7e0a28cee66ce1347458bc67525b1";
    private string $ghlLocationApiKey= "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJsb2NhdGlvbl9pZCI6IlppelBuSE9WbTNlWFFwekxVTkhVIiwiY29tcGFueV9pZCI6IlVqaU92WTlxVWRaOTZkSk84UzNIIiwidmVyc2lvbiI6MSwiaWF0IjoxNjQyOTEwOTgzMzIxLCJzdWIiOiJIYXFjSzFodW9UUTJ0QUYxM1kwaCJ9.T416FqDJNATyj8UcrfmuQJCEsSs0LtHoEAn2_7S0nhY";
    private string $ghlAgencyApiKey = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJjb21wYW55X2lkIjoiVWppT3ZZOXFVZFo5NmRKTzhTM0giLCJ2ZXJzaW9uIjoxLCJpYXQiOjE2NTM2MDA4NzY3MzgsInN1YiI6IlI1Q0RMcE9FMTNFWHc5clpKWmhkIn0.lbBUdVVeavvZue_JPn4JHRVGSyibT0K6Z3zrG_-8Ijk";
    private string $ghlLocationID = 'ZizPnHOVm3eXQpzLUNHU';

    private array $fpLeads = [];
    private array $ghlUsers = [];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        //TODO Call getGHLUsers ->  getFPLeads (Inside this we'll call getGHLLeadId) -> updateGHLLead  Done :)
        $this->getGHLUsers();
        $this->getFPLeads();
        $this->updateGHLLead();
        return 0;
    }

    private function getFPLeads(int $page = 1): array|string
    {
        try {
            $http = Http::withHeaders([
                'x-api-key' => $this->fpApiKey
            ]);
            if ($page > 1){
                $response = $http->get('https://firstpromoter.com/api/v1/leads/list?page=' . $page);
            } else {
                $response = $http->get('https://firstpromoter.com/api/v1/leads/list');
            }
            if ($response->ok()){
                $total = (int) $response->header('Total');
                $totalPage = (int) ceil($total / 50);
                foreach ($response->json() as $lead){
                    $email = trim(rtrim(ltrim(strtolower($lead['email']))));
                    $contact = Contact::findByEmail($email);
                    if (!$contact->ghl_id){
                        $contact->ghl_id = $this->getGHLLeadId($email);
                    }
                    if (!$contact->assign_to){
                        $contact->assign_to = $this->ghlUsers[trim(ltrim(rtrim(strtolower($lead['promoter']['email']))))]??null;
                    }
                    $contact->fp_data = $lead;
                    $contact->save();
                    if (!$contact->sync){
                        $this->fpLeads[] = $contact;
                    }
                }
                if ($totalPage !== $page){
                    $page++;
                    return $this->getFPLeads($page);
                } else {
                    return $this->fpLeads;
                }
            } else {
                $this->error('Something is wrong while getting FP Leads.');
            }
        } catch (\Exception $e){
            $this->error($e->getMessage());
        }
        return '';
    }

    private function getGHLLeadId(string $email): string | null{
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->ghlLocationApiKey
            ])->get('https://rest.gohighlevel.com/v1/contacts/lookup?email=' . urlencode($email));
            if ($response->ok() && isset($response->json()['contacts'][0]['id'])){
                return $response->json()['contacts'][0]['id']??null;
            } else {
                $this->info("Something is wrong while getting contact ID for " . $email . '.');
                $this->error($response->body());
                return '';
            }
        } catch (\Exception $e) {
            $this->info($e->getMessage());
        }
        return '';
    }

    private function getGHLUsers(): void
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->ghlAgencyApiKey
            ])->get('https://rest.gohighlevel.com/v1/users/?locationId=' . $this->ghlLocationID);
            if ($response->ok()){
                foreach ($response->json() as $users){
                    if (gettype($users) === 'array'){
                        foreach ($users as $user){
                            if ($user['email']){
                                $this->ghlUsers[trim(ltrim(rtrim(strtolower($user['email']))))] = $user['id'];
                            }
                        }
                    }
                }
                return;
            }
        } catch (\Exception $e){
            $this->error($e->getMessage());
        }
    }

    private function updateGHLLead(){
        $contacts = Contact::getUnsynced();
        foreach ($contacts as $contact){
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->ghlLocationApiKey
                ])->put('https://rest.gohighlevel.com/v1/contacts/' . $contact->ghl_id, [
                    'assigned_to'   => $contact->assign_to
                ]);
                if ($response->ok()){
                    $contact->sync();
                }
                $this->info('Sync complete, Contact Email: ' . $contact->email);
            } catch (\Exception $e){
                $this->error("Unable to update GHL contact for " . $contact->email . ' Error:- ' . $e->getMessage());
            }
        }
    }
}
