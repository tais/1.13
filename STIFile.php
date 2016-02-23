<?php
//
//    This program is free software: you can redistribute it and/or modify
//    it under the terms of the GNU General Public License as published by
//    the Free Software Foundation, either version 3 of the License, or
//    (at your option) any later version.
//
//    This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//    GNU General Public License for more details.
//
//    You should have received a copy of the GNU General Public License
//    along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
//    Author: Tais
//    You can find this file @ https://github.com/tais/1.13/
//
//

class STIFile
{
    private $header = array();
    private $filedata = array();
    private $palette = array();
    private $images = array();
    
    public function __construct($filename)
    {
        // somehow php is kinda wonky on memory usage, fread causes this,
        // I've set this to 80000000 to be able to read a 939 picture large MDGUNS.STI file
        // if someone knows another solution feel free, it works, that's the most important for now!
        ini_set ('memory_limit', filesize($filename) + 80000000);

        //open file and do your magic, after this the object contains all image data from the opened STI
        //you can pretty much fire away the showPNGImage() function after this.
        $fh = fopen ($filename, "rb");
        $this->unpackHeader($fh, 24);
        $this->unpackFileData($fh, 40);
        $this->unpackPalette($fh, 768);
        $this->unpackImages($fh);
    }

    //debug function to print the file palette
    public function printPalette()
    {
        $code = "<div style=\"clear:both;width:80px;\">";
        foreach($this->palette as $color)
        {
            $code .= "<div style=\"width:8px;height:8px;margin:1px;float:left;background-color:rgb(".$color[0].",".$color[1].",".$color[2].")\"></div>";
        }
        $code .= "</div>";
        echo $code;
    }

    //debug function to analyze the bitstream of an image
    public function printImageBinary($id=1)
    {
        $code = "";
        $keys = array_keys($this->images[$id]['data']);
        foreach($this->images[$id]['data'] as $part)
        {
            $code .= str_pad(decbin($part), 8, "0", STR_PAD_LEFT)."(".$part.")";
            if($part == 0) $code .= "<br /><br />";
            else $code .= "  |  ";
        }
        echo $code;
    }

    //debug function to print file header data
    public function printFileHeader()
    {
        print_r($this->header);
    }
    //debug function to print file data
    public function printFiledata()
    {
        echo "<div style=\"clear:both;\">";
        print_r($this->filedata);
        echo "</div>";
    }
    //debug function to print file header data for $id
    public function printImageHeader($id=1)
    {
        echo "<div style=\"clear:both;\">";
        print_r($this->images[$id]['headers']);
        echo "</div>";
    }

    //debug function to analyze bitstream array
    public function debugBinary($id=1)
    {
        print_r($this->images[$id]['data']);
    }

    //return amount of images in file
    public function getImageAmount()
    {
        return $this->filedata['imageamount'];
    }

    //build PNG image out of bitstream array
    private function buildPNGImage($id=1)
    {
        $image = [];
        for($i=1;$i<=count($this->images[$id]['data']);$i++)
        {
            if($this->images[$id]['data']['data'.$i] == 0)
            {
                //end of line, all bits are 0, do nothing
            }
            elseif($this->images[$id]['data']['data'.$i] & 128)
            {
                //byte starts with 1, so is transparent pixels
                $amount = $this->images[$id]['data']['data'.$i] - 128;
                for($j=1;$j<=$amount;$j++)
                {
                    //drop palette color array (R,G,B) from index 0 at the end of $image array
                    $image[] = $this->palette[0];
                }
                //no need to up $i counter, transparent pixels are contained in one byte with the amount of pixels
                //if amount of transparent pixels exceeds 127 a new byte will be used
            }
            else
            {
                //no transparent pixel and no end of line, so color data, first byte tells how many pixels
                //succeeding bytes give color values for all these pixels
                $amount = $this->images[$id]['data']['data'.$i];
                for($j=1;$j<=$amount;$j++)
                {
                    //drop palette color array (R,G,B) at the end of $image array
                    $image[] = $this->palette[$this->images[$id]['data']['data'.($i+$j)]];
                }
                //up the $i counter to skip all the color bytes because we just added them
                $i += $amount;
            }
        }
        
        //build actual image with GD, tried it with Imagemagick.. but what was I thinking.... anyway!
        $height = $this->images[$id]['headers']['imageheight'];
        $width = $this->images[$id]['headers']['imagewidth'];
        $img = imagecreatetruecolor($width, $height);

        //image array index is $i, this is used to loop through all pixels in the image array
        $i = 0;
        //loop through all lines in the image we're creating from top bottom
        for($y=0; $y<$height; $y++)
        {
            //loop through all the horizontal pixels in the line
            for($x=0; $x<$width; $x++)
            {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $image[$i][0], $image[$i][1], $image[$i][2]));
                //we have drawn the pixel, next!
                $i++;
            }
        }
        //return image data, this is GD data, needs to be handled by imagepng() or imagejpeg() and needs a proper header to be displayed
        return $img;
    }
    
    //print a PNG image to browser, use this without anything preceding it to display the image as PNG
    public function showPNGImage($id=1)
    {
        $img = $this->buildPNGImage($id);
        header('Content-Type: image/png');
        imagepng($img);
    }

    //unpack file header data
    private function unpackHeader($fh, $size)
    {
        $header = fread($fh, $size);
        $this->header = unpack("A4header/".
                         "L1size/".
                         "L1sizepacked/".
                         "L1transparentcolors/".
                         "L1flags",
                         $header);
    }

    //unpack main file properties
    private function unpackFileData($fh, $size)
    {
        $filedata = fread($fh, $size);
        $this->filedata = unpack("L1palettesize/".
                           "S1imageamount/".
                           "C1redcolordepth/".
                           "C1greencolordepth/".
                           "C1bluecolordepth/".
                           "C11padding/".
                           "C1colordepth/".
                           "L1appdatasize",
                            $filedata);
    }

    //unpack the palette data, each STI has only one palette
    private function unpackPalette($fh, $size)
    {
        $palette = fread($fh, $size);
        $palette = unpack("C*palette", $palette);
        $color = array();
        for($i=1; $i<=count($palette); $i++)
        {
            $color[] = $palette['palette'.$i];
            if($i%3==0)
            {
                $this->palette[] = $color;
                $color = array();
            }
        }
    }
    
    //unpack all the images that reside inside the STI file
    private function unpackImages($fh)
    {
        for($i=0;$i<$this->filedata['imageamount'];$i++)
        {
            $imageheaders = fread($fh, 16);
            $imageheaders = unpack("L1shift/".
                                   "L1databytes/".
                                   "S1imageshifth/".
                                   "S1imageshiftv/".
                                   "S1imageheight/".
                                   "S1imagewidth",
                                    $imageheaders);
            $this->images[$i]['headers'] = $imageheaders;
        }

        for($i=0;$i<$this->filedata['imageamount'];$i++)
        {
            $imagedata = fread($fh, $this->images[$i]['headers']['databytes']);
            $imagedata = unpack("C*data/", $imagedata);
            $this->images[$i]['data'] = $imagedata;
        }
    }
}