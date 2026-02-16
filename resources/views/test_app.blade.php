<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FamilyApp - Labo de Test</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
</head>
<body class="bg-slate-100 p-8 font-sans text-slate-800">

<div class="max-w-6xl mx-auto">
    <header class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-extrabold text-indigo-700 tracking-tight">📱 FamilyApp <span class="text-indigo-400 font-light">Laboratory</span></h1>
        <div id="status-bar" class="hidden text-sm font-mono bg-white px-3 py-1 rounded shadow text-green-600">
            🟢 Connecté
        </div>
    </header>

    <div id="login-section" class="bg-white p-8 rounded-xl shadow-lg mb-8 max-w-md mx-auto">
        <h2 class="text-xl font-bold mb-4 text-slate-700">1. Connexion Parent</h2>
        <div class="space-y-4">
            <input type="email" id="email" placeholder="Email (ex: jean@example.com)" class="w-full border p-3 rounded-lg focus:ring-2 focus:ring-indigo-400 outline-none" value="jean@example.com">
            <input type="password" id="password" placeholder="Mot de passe" class="w-full border p-3 rounded-lg focus:ring-2 focus:ring-indigo-400 outline-none" value="password">
            <button onclick="login()" class="w-full bg-indigo-600 text-white py-3 rounded-lg font-bold hover:bg-indigo-700 transition">Se connecter</button>
        </div>
    </div>

    <div id="setup-section" class="hidden bg-yellow-50 p-8 rounded-xl shadow-lg mb-8 border-l-4 border-yellow-400">
        <h2 class="text-xl font-bold mb-4 text-yellow-800">2. Créer mon Foyer</h2>
        <p class="mb-4 text-sm text-yellow-700">Premier démarrage détecté. Configure ta maison :</p>
        <input type="text" id="household-name" placeholder="Nom de la maison (ex: Chez les DUPONT)" class="w-full border p-3 rounded-lg mb-4">

        <div class="flex gap-4 mb-6 text-sm font-medium text-slate-600">
            <label class="flex items-center gap-2"><input type="checkbox" id="mod-meals" checked class="w-4 h-4"> Repas</label>
            <label class="flex items-center gap-2"><input type="checkbox" id="mod-tasks" checked class="w-4 h-4"> Tâches</label>
            <label class="flex items-center gap-2"><input type="checkbox" id="mod-budget" checked class="w-4 h-4"> Budget</label>
        </div>

        <button onclick="createHousehold()" class="bg-yellow-600 text-white px-6 py-2 rounded-lg font-bold hover:bg-yellow-700">Créer le foyer</button>
    </div>

    <div id="dashboard-section" class="hidden space-y-8">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white p-6 rounded-xl shadow-md border-t-4 border-green-500 col-span-2">
                <h2 class="text-2xl font-bold text-slate-800 flex items-center gap-2" id="dash-title">
                    🏠 Maison...
                </h2>

                <div class="mt-6">
                    <h3 class="font-bold text-slate-400 uppercase text-xs tracking-wider mb-3">Membres de la famille</h3>
                    <ul id="members-list" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    </ul>
                </div>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-md border-t-4 border-purple-500">
                <h2 class="text-lg font-bold mb-4 text-purple-700">➕ Nouveau Membre</h2>
                <div class="space-y-3">
                    <input type="text" id="new-name" placeholder="Prénom" class="w-full border p-2 rounded text-sm">
                    <input type="email" id="new-email" placeholder="Email (Optionnel pour enfant)" class="w-full border p-2 rounded text-sm">
                    <select id="new-role" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="enfant">Enfant</option>
                        <option value="parent">Parent (Conjoint)</option>
                    </select>
                    <button onclick="addMember()" class="w-full bg-purple-600 text-white py-2 rounded font-bold hover:bg-purple-700 text-sm">Générer accès WhatsApp</button>
                </div>

                <div id="whatsapp-box" class="hidden mt-4 bg-green-50 p-3 rounded border border-green-200">
                    <p class="font-bold text-green-800 text-xs mb-1">Message à copier :</p>
                    <textarea id="whatsapp-text" class="w-full h-24 p-2 border rounded text-xs font-mono bg-white resize-none" readonly></textarea>
                </div>
            </div>
        </div>

        <div id="recipes-section" class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <div class="bg-white p-6 rounded-xl shadow-md border-t-4 border-orange-500 h-[500px] flex flex-col">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold text-slate-800">🍲 Carnet de Recettes</h2>
                    <button onclick="loadRecipes()" class="text-xs text-orange-600 hover:underline font-bold uppercase">Rafraîchir</button>
                </div>
                <div class="overflow-y-auto flex-1 pr-2">
                    <div id="recipes-list" class="space-y-4">
                        <div class="text-center text-slate-400 italic mt-10">Chargement...</div>
                    </div>
                </div>
            </div>

            <div class="space-y-6">

                <div class="bg-white p-6 rounded-xl shadow-md border-t-4 border-indigo-600 relative overflow-hidden">
                    <div class="absolute top-0 right-0 bg-indigo-100 text-indigo-800 text-[10px] font-bold px-2 py-1 rounded-bl uppercase tracking-wider">Powered by AI</div>

                    <h3 class="font-bold text-indigo-700 mb-4 text-lg">✨ Chef IA Générateur</h3>
                    <p class="text-xs text-slate-500 mb-3">Tape un nom de plat, l'IA génère les ingrédients, quantités et catégories pour toi.</p>

                    <div class="flex gap-2 mb-4">
                        <input type="text" id="ai-prompt" placeholder="Ex: Risotto champignons, Lasagne..." class="flex-1 border p-3 rounded-lg focus:ring-2 focus:ring-indigo-300 outline-none shadow-sm">
                        <button onclick="previewAiRecipe()" class="bg-indigo-600 text-white px-5 rounded-lg hover:bg-indigo-700 font-bold shadow transition">Générer</button>
                    </div>

                    <div id="ai-loader" class="hidden text-center py-8 text-indigo-500 animate-pulse bg-indigo-50 rounded-lg">
                        <div class="text-2xl mb-2">🧠</div>
                        <div class="text-sm font-bold">L'IA réfléchit et catégorise...</div>
                    </div>

                    <div id="ai-preview" class="hidden bg-slate-50 p-4 rounded-lg border border-indigo-100 text-sm">
                        <div class="flex justify-between items-start mb-2">
                            <h4 id="ai-prev-title" class="font-bold text-lg text-indigo-900"></h4>
                            <span id="ai-prev-type" class="text-[10px] bg-white border border-indigo-200 px-2 py-1 rounded text-slate-500 uppercase font-bold tracking-wide"></span>
                        </div>

                        <div class="mt-3 bg-white p-3 rounded border border-slate-200">
                            <p class="font-bold text-xs text-slate-400 uppercase mb-2">Ingrédients & Rayons</p>
                            <ul id="ai-prev-ingredients" class="space-y-2 text-slate-700 max-h-40 overflow-y-auto"></ul>
                        </div>

                        <div class="mt-4 flex gap-3">
                            <button onclick="cancelAi()" class="flex-1 bg-white border border-slate-300 text-slate-600 py-2 rounded-lg hover:bg-slate-50 font-medium">Annuler</button>
                            <button onclick="saveAiRecipe()" class="flex-1 bg-green-600 text-white py-2 rounded-lg hover:bg-green-700 font-bold shadow">Valider & Sauvegarder</button>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 opacity-75 hover:opacity-100 transition">
                    <h3 class="font-bold text-slate-600 mb-2 text-sm">🛠️ Debug: Ajout Manuel</h3>
                    <button onclick="addManualTestRecipe()" class="w-full bg-slate-200 text-slate-600 py-2 rounded hover:bg-slate-300 transition text-sm font-medium">
                        Injecter "Pâtes Carbonara" (Test DB)
                    </button>
                </div>

            </div>
        </div>
    </div>

    <div class="mt-8 p-4 bg-slate-900 text-green-400 font-mono text-xs rounded-lg h-32 overflow-y-auto shadow-inner" id="debug-log">
        <div class="text-slate-500 border-b border-slate-800 pb-1 mb-2">Console de debug système...</div>
    </div>
</div>

<script>
    // --- CONFIGURATION ---
    const API_URL = 'http://127.0.0.1:8000/api';
    let token = localStorage.getItem('family_token');
    let currentHouseholdId = null;
    let currentAiData = null;

    // Config Axios
    const api = axios.create({ baseURL: API_URL });
    if(token) {
        api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    }

    // --- OUTILS ---
    function log(msg) {
        const el = document.getElementById('debug-log');
        const time = new Date().toLocaleTimeString();
        el.innerHTML += `<div class="mb-1"><span class="opacity-50">[${time}]</span> ${msg}</div>`;
        el.scrollTop = el.scrollHeight;
    }

    // 1. LOGIN
    async function login() {
        try {
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;

            log(`🔐 Connexion de ${email}...`);
            const res = await api.post('/login', { email, password });

            token = res.data.token;
            localStorage.setItem('family_token', token);
            api.defaults.headers.common['Authorization'] = `Bearer ${token}`;

            document.getElementById('status-bar').classList.remove('hidden');
            document.getElementById('login-section').classList.add('hidden');

            loadDashboard();
        } catch (e) {
            log("❌ ERREUR LOGIN: " + (e.response?.data?.message || e.message));
            alert("Erreur de connexion");
        }
    }

    // 2. DASHBOARD
    async function loadDashboard() {
        try {
            log("📡 Chargement Dashboard...");
            const res = await api.get('/dashboard');

            if (res.data.requires_setup) {
                log("⚠️ Utilisateur sans foyer.");
                document.getElementById('setup-section').classList.remove('hidden');
            } else {
                showDashboard(res.data);
            }
        } catch (e) {
            log("❌ ERREUR DASHBOARD: " + e.message);
        }
    }

    function showDashboard(data) {
        document.getElementById('setup-section').classList.add('hidden');
        document.getElementById('dashboard-section').classList.remove('hidden');

        // Info Foyer
        document.getElementById('dash-title').innerText = "🏠 " + data.household_name;

        // Récupération ID Foyer (via le premier membre trouvé)
        if(data.members && data.members.length > 0) {
            currentHouseholdId = data.members[0].pivot.household_id;
            log(`✅ ID Foyer détecté : ${currentHouseholdId}`);
        }

        // Liste Membres
        const list = document.getElementById('members-list');
        list.innerHTML = '';
        data.members.forEach(m => {
            const roleClass = m.pivot.role === 'parent' ? 'bg-indigo-100 text-indigo-700' : 'bg-pink-100 text-pink-700';
            list.innerHTML += `
                    <li class="bg-slate-50 p-3 rounded-lg border border-slate-200 flex justify-between items-center">
                        <div>
                            <div class="font-bold text-slate-700 text-sm">${m.name}</div>
                            <div class="text-xs text-slate-400 truncate w-32">${m.email}</div>
                        </div>
                        <span class="text-[10px] font-bold uppercase px-2 py-1 rounded ${roleClass}">${m.pivot.role}</span>
                    </li>`;
        });

        // Charger les recettes
        loadRecipes();
    }

    // 3. RECETTES (LISTE)
    async function loadRecipes() {
        const list = document.getElementById('recipes-list');
        try {
            const res = await api.get('/recipes');
            list.innerHTML = '';

            if(res.data.length === 0) {
                list.innerHTML = '<div class="p-8 text-center text-slate-400 bg-slate-50 rounded-lg">Aucune recette pour l\'instant.<br>Lance l\'IA pour commencer ! 🤖</div>';
                return;
            }

            res.data.forEach(r => {
                // Badge Type
                const typeColor = r.type === 'dessert' ? 'bg-pink-100 text-pink-800' : 'bg-blue-100 text-blue-800';

                // Badges Ingrédients (si chargés)
                let ingHtml = '';
                if (r.ingredients) {
                    ingHtml = r.ingredients.map(i => {
                        let catColor = 'bg-slate-100 text-slate-600 border-slate-200';
                        if(i.category === 'fruits et légumes') catColor = 'bg-green-50 text-green-700 border-green-200';
                        if(i.category === 'boucherie') catColor = 'bg-red-50 text-red-700 border-red-200';
                        if(i.category === 'crèmerie') catColor = 'bg-yellow-50 text-yellow-700 border-yellow-200';
                        if(i.category === 'épicerie salée') catColor = 'bg-orange-50 text-orange-700 border-orange-200';

                        return `<span class="inline-flex items-center px-2 py-1 rounded text-[10px] font-medium border ${catColor} mr-1 mb-1">
                                ${i.name} <span class="ml-1 opacity-60">(${i.pivot.quantity}${i.pivot.unit})</span>
                            </span>`;
                    }).join('');
                }

                list.innerHTML += `
                        <div class="bg-white p-4 rounded-lg border border-slate-200 hover:shadow-md transition group">
                            <div class="flex justify-between items-start mb-2">
                                <h3 class="font-bold text-slate-800">${r.title}</h3>
                                <span class="${typeColor} text-[10px] px-2 py-0.5 rounded uppercase font-bold tracking-wide">${r.type}</span>
                            </div>
                            <div class="flex flex-wrap">
                                ${ingHtml}
                            </div>
                        </div>`;
            });
        } catch (e) {
            list.innerHTML = '<div class="text-red-500 text-sm">Erreur chargement recettes</div>';
        }
    }

    // 4. IA : PREVIEW (Mis à jour pour route preview-ai)
    async function previewAiRecipe() {
        const prompt = document.getElementById('ai-prompt').value;
        if(!prompt) return alert("Écris un nom de plat !");

        document.getElementById('ai-loader').classList.remove('hidden');
        document.getElementById('ai-preview').classList.add('hidden');

        try {
            log(`🤖 IA: Génération de "${prompt}"...`);

            const res = await api.post('/recipes/preview-ai', { title: prompt });

            // DEBUG : Affiche ce que le serveur renvoie vraiment
            console.log("🔍 Réponse brute du serveur :", res.data);

            // SÉCURITÉ : On vérifie si les ingrédients sont là
            if (!res.data || !res.data.ingredients) {
                throw new Error("L'IA n'a pas renvoyé de liste d'ingrédients valide.");
            }

            currentAiData = res.data;
            displayAiPreview(res.data);

        } catch (e) {
            console.error(e); // Affiche l'erreur complète dans la console F12
            log("❌ ERREUR IA: " + (e.response?.data?.message || e.message));
            alert("Oups ! L'IA a échoué. Regarde les logs (zone noire) pour le détail.");
        } finally {
            document.getElementById('ai-loader').classList.add('hidden');
        }
    }

    function displayAiPreview(data) {
        document.getElementById('ai-preview').classList.remove('hidden');

        // Sécurité sur les champs texte
        document.getElementById('ai-prev-title').innerText = data.title || "Titre inconnu";
        document.getElementById('ai-prev-type').innerText = data.type || 'plat principal';

        const list = document.getElementById('ai-prev-ingredients');
        list.innerHTML = '';

        // Sécurité supplémentaire : on est sûr que ingredients existe grâce au check précédent
        if (Array.isArray(data.ingredients)) {
            data.ingredients.forEach(i => {
                list.innerHTML += `
                        <li class="flex justify-between items-center border-b border-indigo-50 py-2 last:border-0 text-xs">
                            <span class="font-medium text-slate-700">${i.name}</span>
                            <div class="text-right flex items-center gap-2">
                                <span class="font-bold text-indigo-600">${i.quantity}${i.unit}</span>
                                <span class="bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded uppercase text-[9px]">${i.category || '?'}</span>
                            </div>
                        </li>`;
            });
        } else {
            list.innerHTML = '<li class="text-red-500">Erreur format ingrédients</li>';
        }
    }
    // 5. IA : SAVE (Mis à jour pour route ai-store)
    async function saveAiRecipe() {
        if(!currentAiData || !currentHouseholdId) {
            alert("Données manquantes ou Foyer non détecté.");
            return;
        }

        const payload = {
            ...currentAiData,
            household_id: currentHouseholdId
        };

        try {
            log("💾 Sauvegarde en base de données...");
            // MISE A JOUR ICI : /recipes/ai-store
            await api.post('/recipes/ai-store', payload);

            log("✅ Recette IA Sauvegardée !");
            document.getElementById('ai-preview').classList.add('hidden');
            document.getElementById('ai-prompt').value = '';
            currentAiData = null;

            loadRecipes();
        } catch (e) {
            log("❌ ERREUR SAVE: " + (e.response?.data?.message || e.message));
        }
    }

    function cancelAi() {
        document.getElementById('ai-preview').classList.add('hidden');
        currentAiData = null;
    }

    // 6. AJOUT MANUEL
    async function addManualTestRecipe() {
        const recipeData = {
            title: "Pâtes Carbonara (Test)",
            type: "plat principal",
            description: "Test manuel",
            instructions: "Test...",
            ingredients: [
                { name: "Spaghetti", quantity: 500, unit: "g", category: "épicerie salée" },
                { name: "Lardons", quantity: 200, unit: "g", category: "boucherie" },
                { name: "Crème", quantity: 20, unit: "cl", category: "crèmerie" }
            ]
        };
        try {
            await api.post('/recipes', recipeData);
            log("🛠️ Recette manuelle ajoutée.");
            loadRecipes();
        } catch (e) { alert("Erreur ajout manuel"); }
    }

    // 7. CRÉER FOYER (Mis à jour pour route households pluriel)
    async function createHousehold() {
        try {
            const name = document.getElementById('household-name').value;
            const modules = [];
            if(document.getElementById('mod-meals').checked) modules.push('meals');
            if(document.getElementById('mod-tasks').checked) modules.push('tasks');
            if(document.getElementById('mod-budget').checked) modules.push('budget');

            // MISE A JOUR ICI : /households
            await api.post('/households', { name, modules });
            log("🏠 Foyer créé !");
            loadDashboard();
        } catch (e) { log("Erreur création foyer"); }
    }

    // 8. AJOUTER MEMBRE
    async function addMember() {
        try {
            const name = document.getElementById('new-name').value;
            const email = document.getElementById('new-email').value;
            const role = document.getElementById('new-role').value;

            const res = await api.post('/household/members', { name, email, role });

            document.getElementById('whatsapp-box').classList.remove('hidden');
            document.getElementById('whatsapp-text').value = res.data.share_text;
            log("👤 Membre ajouté.");
            loadDashboard();
        } catch (e) { alert("Erreur ajout membre"); }
    }
</script>
</body>
</html>
