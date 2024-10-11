<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Twilio\Rest\Client;
use Carbon\Carbon;

class AuthController extends Controller
{
    
    public function register(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'avatar' => 'nullable|image|mimes:jpeg,png,jpg,svg,gif|max:6048',
                'first_name' => 'required|string',
                'last_name' => 'required|string',
                'phone' => 'required|string|unique:users',
                'email' => 'nullable|string|email',
                'password' => 'required|string',
            ]);

            // Ajouter automatiquement le code pays si absent
            $phone = $this->addCountryCode($validatedData['phone']);

            $user = User::create([
                'avatar' => $validatedData['avatar'] ?? null,
                'first_name' => $validatedData['first_name'],
                'last_name' => $validatedData['last_name'],
                'phone' => $phone,
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
                'is_verified' => false,
                'verification_code' => rand(1000, 9999),
                'verification_code_expires_at' => Carbon::now()->addMinutes(10),
            ]);


            // Envoi du code de vérification via Twilio
            $this->sendCode($user->phone, $user->verification_code);

            // Génération du token d'authentification
            $token = $user->createToken('authToken', ['*'], now()->addMonths(3)); // 3 mois de validité

            return response()->json([
                'message' => 'Inscription réussie, un code de vérification a été envoyé.',
                'user' => $user,
                'token' => $token->plainTextToken,
            ], 201);

        } 
        
        catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }




    public function login(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'phone' => 'required|string',
                'password' => 'required|string',
            ]);

            // Ajouter automatiquement le code pays si absent
            $phone = $this->addCountryCode($validatedData['phone']); 
    
            // Trouver l'utilisateur en fonction de son numéro de téléphone
            $user = User::where('phone', $phone)->first();
    
            // Vérifier si l'utilisateur existe et si le mot de passe est correct
            if (!$user || !Hash::check($validatedData['password'], $user->password)) {
                return response()->json(['message' => 'Numéro de téléphone ou mot de passe incorrect'], 401);
            }
    
            $user->verification_code = rand(1000, 9999);
            $user->verification_code_expires_at = Carbon::now()->addMinutes(10); // Code valide pendant 10 minutes
            $user->save();
    
            // Envoyer le code de vérification via Twilio
            $this->sendCode($user->phone, $user->verification_code);
    
            // Générer un token Sanctum pour l'utilisateur
            $token = $user->createToken('authToken', ['*'], now()->addMonths(3)); // Token valide pour 3 mois
    
            return response()->json([
                'message' => 'Connexion réussie, un code de vérification a été envoyé.',
                'user' => $user,
                'token' => $token->plainTextToken, 
            ], 200);
        }

        catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function request_reset(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'phone' => 'required|string',
            ]);
    
            // Ajouter automatiquement le code pays si absent
            $phone = $this->addCountryCode($validatedData['phone']); 
    
            // Rechercher l'utilisateur avec ce numéro de téléphone
            $user = User::where('phone', $phone)->first();
    
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non trouvé'], 404);
            }
    
            $user->verification_code = rand(1000, 9999);
            $user->verification_code_expires_at = Carbon::now()->addMinutes(10); // Valide pour 10 minutes
            $user->save();
    
            // Envoyer le code de vérification via Twilio
            $this->sendCode($user->phone, $user->verification_code);

            // Générer un token Sanctum pour l'utilisateur
            $token = $user->createToken('authToken', ['*'], now()->addMonths(3)); // Token valide pour 3 mois
    
            return response()->json([
                'message' => 'Code de vérification envoyé', 
                'token' => $token->plainTextToken, 
                ], 200);
        }

        catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function password_reset(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'password' => 'required|string', 
            ]);

            // Récupérer l'utilisateur authentifié
            $user = auth()->user(); 

            if (!$user) {
                return response()->json(['message' => 'Utilisateur non trouvé'], 404);
            }

            // Réinitialiser le mot de passe
            $user->password = Hash::make($validatedData['password']);
            $user->save();

            return response()->json(['message' => 'Mot de passe réinitialisé avec succès'], 200);
        } 
        
        catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }





    private function addCountryCode($phone)
    {
        // Vérifier si le numéro commence par un '+'. Si non, ajouter le code pays
        if (!str_starts_with($phone, '+')) {
            $phone = '+225' . $phone;
        }

        return $phone;
    }



    private function sendCode($phone, $verification_code)
    {
        $twilio = new Client(env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN'));

        $twilio->messages->create($phone, [
            'from' => env('TWILIO_FROM'),
            'body' => "Votre code de vérification est : $verification_code",
            // Désactiver la vérification SSL temporairement
            'curl_options' => [
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ]
        ]);
    }
    


    public function verifie_code(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'verification_code' => 'required|numeric',
            ]);
    
            $user = auth()->user(); // Récupération de l'utilisateur authentifié via le token Sanctum
    
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non trouvé'], 404);
            }
    
            // Vérification si le code correspond et s'il n'est pas expiré
            if ($user->verification_code == $validatedData['verification_code'] &&
                Carbon::now()->lt($user->verification_code_expires_at)) {
    
                $user->is_verified = true;
                $user->verification_code = null; 
                $user->verification_code_expires_at = null; 
                $user->save();
    
                return response()->json(['message' => 'Vérification réussie, inscription complète'], 200);
            } 
            
            else {
                return response()->json(['message' => 'Code invalide ou expiré'], 400);
            }
        }

        catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Deconnexion reussie'], 200);
    }

}
