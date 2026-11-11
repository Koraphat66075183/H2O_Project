<?php 
// -------------------- Database Connection --------------------
$host = "localhost";
$user = "cpe406";  
$pass = "lukmoo";  
$db   = "sensor_db";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// -------------------- Determine which house to insert --------------------
$lastHouse = $conn->query("SELECT house FROM sensors ORDER BY created_at DESC LIMIT 1");
$house = 1;
if($lastHouse && $lastHouse->num_rows>0){
    $last = $lastHouse->fetch_assoc();
    $house = ($last['house']==1)?2:1;
}

// -------------------- Insert New Data --------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $water_level = $_POST['water_level'];
    $water_turbidity = $_POST['water_turbidity'];
    $stmt = $conn->prepare("INSERT INTO sensors (house, water_level, water_turbidity) VALUES (?, ?, ?)");
    $stmt->bind_param("ids", $house, $water_level, $water_turbidity);
    $stmt->execute();
    $stmt->close();
}

// -------------------- Fetch Latest Data per house --------------------
$latest = [];
for($h=1;$h<=2;$h++){
    $res = $conn->query("SELECT * FROM sensors WHERE house=$h ORDER BY created_at DESC LIMIT 1");
    $latest[$h] = $res ? $res->fetch_assoc() : null;
}

// -------------------- Fetch Graph Data per house --------------------
$graphData = [];
for($h=1;$h<=2;$h++){
    $res = $conn->query("SELECT * FROM sensors WHERE house=$h ORDER BY created_at ASC");
    $graphData[$h] = [];
    while($row = $res->fetch_assoc()) {
        $graphData[$h][] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>💧 Water Sales Dashboard | Sangkhlaburi</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
<script src="https://unpkg.com/scrollreveal"></script>

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

<style>
:root{
    --primary:#1e90ff;
    --secondary:#00b894;
    --accent:#ff7675;
    --text-color:#2d3436;
    --card-bg:#ffffffdd;
    --shadow:rgba(0,0,0,0.15);
}
*{box-sizing:border-box;}
body, html{margin:0;padding:0;font-family:'Roboto',sans-serif;background:#f5f6fa;color:var(--text-color);}
header{
    width:100%;background:var(--primary);color:#fff;text-align:center;padding:1em 0;font-size:1.8em;font-weight:700;box-shadow:0 4px 8px var(--shadow);position:fixed;top:0;z-index:1000;
    letter-spacing:1px;
}
main{
    display:flex;flex-direction:row;width:100%;height:100vh;padding-top:70px;gap:20px;box-sizing:border-box;
}
.left-panel{flex:1;position:relative;overflow:hidden;border-radius:15px;box-shadow:0 6px 15px var(--shadow);}
#map{position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;transition:transform 0.3s;border-radius:15px;}
.right-panel{flex:1.2;display:flex;flex-direction:column;gap:20px;overflow-y:auto;}
.card{
    background:var(--card-bg);border-radius:15px;padding:1.5em;box-shadow:0 4px 20px var(--shadow);transition:all 0.3s;
}
.card:hover{transform:translateY(-6px) scale(1.02);box-shadow:0 12px 25px rgba(0,0,0,0.25);}
.card h2{margin-top:0;color:var(--secondary);font-size:1.5em;font-weight:700;}
form{display:flex;flex-direction:column;gap:15px;}
input[type=range]{width:100%;accent-color:var(--primary);cursor:pointer;}
output{margin-left:10px;font-weight:700;color:var(--text-color);}
select{padding:8px;border-radius:10px;border:1px solid #dfe6e9;}
button{
    background-color:var(--primary);color:#fff;border:none;padding:12px;border-radius:10px;cursor:pointer;font-size:16px;transition:0.3s;font-weight:500;
}
button:hover{background-color:var(--secondary);transform:scale(1.05);}
#sensorChart1,#sensorChart2{width:100%;height:260px;border-radius:10px;}
footer{text-align:center;font-size:14px;color:#636e72;padding:15px 0;border-top:1px solid #dfe6e9;margin-top:10px;}
#reasonBox{margin-top:10px;color:#d63031;font-weight:700;white-space:pre-wrap;}
@media(max-width:1200px){main{flex-direction:column;padding-top:100px;}.left-panel,.right-panel{flex:1 1 auto;height:50vh;}#sensorChart1,#sensorChart2{height:200px;}}
</style>
</head>

<body>
<header>💧 Water Sales Dashboard | Sangkhlaburi</header>
<main>
    <div class="left-panel card animate__animated animate__fadeInLeft">
        <h2>📍 Map Location</h2>
        <div id="map"></div>
    </div>

    <div class="right-panel">
        <div class="card animate__animated animate__fadeInRight" id="dashboardCard">
            <h2>🚚 Delivery Dashboard</h2>
            <p><strong>📍 Destination:</strong> <span id="destName">-</span></p>
            <p><strong>📏 Distance:</strong> <span id="distanceVal">-</span> km</p>
            <p><strong>💧 Water Used:</strong> <span id="waterUsed">-</span> m³</p>
            <p><strong>🚗 Trip Cost:</strong> 800 Baht (400 × 2 trips)</p>
            <p><strong>💸 Total Cost:</strong> <span id="totalCost">-</span> Baht</p>
            <div id="reasonBox"></div>
        </div>

        <div class="card animate__animated animate__fadeInRight">
            <h2>📝 Submit Water Data</h2>
            <form method="POST">
                <div>
                    <label>💧 Water Level (cm)</label>
                    <input type="range" name="water_level" min="0" max="200" value="50" oninput="this.nextElementSibling.value=this.value">
                    <output>50</output>
                </div>
                <div>
                    <label>🌊 Water Turbidity</label>
                    <select name="water_turbidity">
                        <option value="Clear">Clear</option>
                        <option value="Slightly Cloudy">Slightly Cloudy</option>
                        <option value="Cloudy">Cloudy</option>
                        <option value="Brown">Brown</option>
                    </select>
                </div>
                <button type="submit">Submit</button>
            </form>
        </div>

        <!-- House 1 -->
        <div class="card animate__animated animate__fadeInRight">
            <h2>🏠 House 1 Latest Data</h2>
            <p><strong>💧 Water Level:</strong> <?php echo $latest[1]['water_level'] ?? '-'; ?> cm</p>
            <p><strong>🌊 Turbidity:</strong> <?php echo $latest[1]['water_turbidity'] ?? '-'; ?></p>
            <canvas id="sensorChart1"></canvas>
        </div>

        <!-- House 2 -->
        <div class="card animate__animated animate__fadeInRight">
            <h2>🏠 House 2 Latest Data</h2>
            <p><strong>💧 Water Level:</strong> <?php echo $latest[2]['water_level'] ?? '-'; ?></p>
            <p><strong>🌊 Turbidity:</strong> <?php echo $latest[2]['water_turbidity'] ?? '-'; ?></p>
            <canvas id="sensorChart2"></canvas>
        </div>
    </div>
</main>

<footer>© 2025 Water Sales Dashboard | Designed with ❤️ by Korraphat / Kawinthip</footer>

<script>
// ---------- Turbidity color mapping ----------
const turbidityColorMap = {'Clear':'green','Slightly Cloudy':'yellow','Cloudy':'orange','Brown':'brown'};

// ---------- Chart ----------
function createChart(chartId, houseData){
    const labels = houseData.map(d=>d.created_at);
    const waterLevels = houseData.map(d=>d.water_level);
    const barColors = houseData.map(d=>turbidityColorMap[d.water_turbidity] || 'grey');
    new Chart(document.getElementById(chartId).getContext('2d'),{
        type:'bar',
        data:{labels,datasets:[{label:'Water Level (cm)',data:waterLevels,backgroundColor:barColors,borderRadius:6}]},
        options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{title:{display:true,text:'Water Level (cm)'}},x:{title:{display:true,text:'Timestamp'}}}}
    });
}

const house1Data = <?php echo json_encode($graphData[1]); ?>;
const house2Data = <?php echo json_encode($graphData[2]); ?>;
createChart('sensorChart1', house1Data);
createChart('sensorChart2', house2Data);

// ---------- Map ----------
const map = L.map('map').setView([15.155,98.453],12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
const base=[15.155,98.453];
let markers=[]; let houseCount=0;

// ---------- Distance ----------
function getDistance(lat1,lon1,lat2,lon2){
    const R=6371;
    const dLat=(lat2-lat1)*Math.PI/180;
    const dLon=(lon2-lon1)*Math.PI/180;
    const a=Math.sin(dLat/2)**2+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)**2;
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

// ---------- Geocoder ----------
const geocoder = L.Control.geocoder({defaultMarkGeocode:false}).on('markgeocode',function(e){
    if(houseCount>=2)return;
    addHouseMarker(e.geocode.center,e.geocode.name);
}).addTo(map);

// ---------- Double Click to add houses ----------
map.on('dblclick',function(e){
    markers.forEach(m=>map.removeLayer(m));
    markers=[]; houseCount=0;
    addHouseMarker(e.latlng,"Custom Location 1");
});

// ---------- Add house marker ----------
function addHouseMarker(latlng,name){
    if(houseCount>=2) return;
    const turbidityLevels=['Clear','Slightly Cloudy','Cloudy','Brown'];
    const turbidity=turbidityLevels[Math.floor(Math.random()*4)];
    const circleColor=turbidityColorMap[turbidity];
    const circle=L.circleMarker(latlng,{color:circleColor,fillColor:circleColor,fillOpacity:0.6,radius:15})
        .addTo(map).bindPopup(`🏠 House ${houseCount+1}<br>💧 Turbidity: ${turbidity}`).openPopup();
    markers.push(circle);
    houseCount++;

    const dist=getDistance(base[0],base[1],latlng.lat,latlng.lng);
    const usedWater=Math.random()*30;
    const tripCost=800;
    const waterCost=usedWater*10;
    let total=tripCost+waterCost;
    let reason="";
    if(total>1000){
        reason=`❗ ค่ารวมเกิน 1,000 บาท เนื่องจาก:
- ค่ารถไป-กลับ = 800 บาท (400 ต่อเที่ยว)
- ปริมาณน้ำที่ใช้ = ${usedWater.toFixed(1)} m³ × 10 บาท = ${waterCost.toFixed(2)} บาท
รวมทั้งหมด = ${total.toFixed(2)} บาท`;
        total=1000;
    }
    document.getElementById('destName').textContent=name;
    document.getElementById('distanceVal').textContent=dist.toFixed(2);
    document.getElementById('waterUsed').textContent=usedWater.toFixed(1);
    document.getElementById('totalCost').textContent=total.toFixed(2);
    document.getElementById('reasonBox').textContent=reason;

    if(houseCount<2){
        map.once('dblclick',function(e2){ addHouseMarker(e2.latlng,"Custom Location 2"); });
    }
}

// ---------- Scroll Reveal ----------
ScrollReveal().reveal('.card',{delay:200,distance:'50px',duration:800,easing:'ease-in-out'});
window.addEventListener('scroll',()=>{document.querySelector('#map').style.transform=`translateY(${window.scrollY*0.3}px)`;});
</script>
</body>
</html>
