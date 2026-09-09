from PIL import Image

def remove_white_bg(input_path, output_path, threshold=240):
    img = Image.open(input_path).convert("RGBA")
    datas = img.getdata()

    newData = []
    for item in datas:
        # If the pixel is very white
        if item[0] > threshold and item[1] > threshold and item[2] > threshold:
            # Calculate an alpha based on how close to pure white it is
            # Pure white (255, 255, 255) -> Alpha 0
            # Threshold (240, 240, 240) -> Alpha 255
            # We'll just use a simple smooth transition or just set to 0.
            # Let's do a simple gradient:
            avg = (item[0] + item[1] + item[2]) / 3
            alpha = int(255 * (255 - avg) / (255 - threshold))
            alpha = max(0, min(255, alpha))
            
            # Since the background is white, we'll also dim the color a bit to avoid white halos
            newData.append((item[0], item[1], item[2], alpha))
        else:
            newData.append(item)

    img.putdata(newData)
    img.save(output_path, "PNG")

remove_white_bg("realistic_flaming_football.jpg", "realistic_flaming_football.png")
