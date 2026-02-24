from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC


morts = [34,37, 39, 40]

base_url = "http://myhordes.localhost"
connexion_url = base_url + "/jx/public/login"
maj_url = base_url + "/jx/beyond/desert/cached"

options = Options()
options.add_argument("--incognito")
options.add_argument("--headless=new")


driver = webdriver.Chrome(options=options)
wait = WebDriverWait(driver, 10)
for u in range (1,41):
    if u not in morts :
        user = f"user_{u:03d}"
        print(user)
        # 1️⃣ Charger la page contenant le formulaire de login
        driver.get(connexion_url)

        # 2️⃣ Attendre que les champs soient présents
        login_name = wait.until(EC.presence_of_element_located((By.ID, "login-name")))
        login_pass = wait.until(EC.presence_of_element_located((By.ID, "login-password")))
        login_button = wait.until(EC.element_to_be_clickable((By.ID, "login_button")))

        # 3️⃣ Remplir les champs
        login_name.send_keys(user)
        login_pass.send_keys("admin")

        # 4️⃣ Cliquer sur le bouton de connexion
        driver.execute_script("arguments[0].click();", login_button)

        # 5️⃣ Attendre que la page soit complètement chargée après login
        wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "li.town-news[x-ajax-href='http://myhordes.localhost/jx/game/raventimes']")))

        # 6️⃣ Aller sur la page de maj
        driver.get(maj_url)

        driver.delete_all_cookies()
    

driver.quit()
