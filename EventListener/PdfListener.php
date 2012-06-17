<?php

/*
 * Copyright 2011 Piotr Śliwa <peter.pl7@gmail.com>
 *
 * License information is in LICENSE file
 */

namespace Knp\Bundle\SnappyBundle\EventListener;

use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;

use Knp\Bundle\SnappyBundle\Annotation\AbstractAnnotation;
use Knp\Bundle\SnappyBundle\Annotation\SnappyPDF;
use Symfony\Component\HttpFoundation\Response;

use Knp\Snappy\GeneratorInterface;
use Doctrine\Common\Annotations\Reader;

/**
 * This listener will replace reponse content by pdf document's content if Pdf annotations is found.
 * Also adds pdf format to request object and adds proper headers to response object.
 * 
 * @author Piotr Śliwa <peter.pl7@gmail.com>
 */
class PdfListener
{
    private $snappy;
    private $reader;
    private $sections;
    
    public function __construct(GeneratorInterface $snappy, Reader $reader, $sections)
    {
        $this->snappy = $snappy;
        $this->reader = $reader;
        $this->sections = $sections;
    }
    
    public function onKernelController(FilterControllerEvent $event)
    {
        if (!is_array($controller = $event->getController())) {
            return;
        }

        $object = new \ReflectionObject($controller[0]);
        $method = $object->getMethod($controller[1]);

        $request = $event->getRequest();
        foreach ($this->reader->getMethodAnnotations($method) as $configuration) {
            if ($configuration instanceof AbstractAnnotation) {
                $request->attributes->set('_'.$configuration->getAliasName(), $configuration);
            }
        }
        
        if(!($annotation = $request->get('_snappyPDF')))
        {
            $annotation = new SnappyPDF(array('active' => true));
            $request->attributes->set('_'.$annotation->getAliasName(), $annotation);
        }
        if($request->getRequestFormat() != 'pdf')
            $annotation->setActive(false);
    }
    
    public function onKernelResponse(FilterResponseEvent $event)
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $annotation = $request->get('_snappyPDF');
        
        if($annotation and $annotation->isActive() and $response->getStatusCode() == 200)
        {
            $options = array();
            $content = $response->getContent();
            
            if($this->sections)
            {
                $doc = new \DOMDocument;
                $doc->loadHTML($content);
                
                $options['header'] = $this->isolate($doc,'header','global')->saveHTML();
                $options['footer'] = $this->isolate($doc,'footer','global')->saveHTML();
                $content = $this->isolate($doc,'content','global')->saveHTML();
            }
            
            $content = $this->snappy->getOutputFromHtml($content,$options);
            
            $headers = array(
                'Content-Length'      => strlen($content),
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => $annotation->getDisposition(),
            );
            foreach($headers as $key => $value)
                $response->headers->set($key, $value);
            
            $response->setContent($content);
        }
    }
    
    public function isolate($doc,$id,$parent)
    {
        $doc = clone $doc;
        
        if(! $element = $doc->getElementById($id))
            return null;
        
        $parent = $doc->getElementById($parent);
        
        while($parent->hasChildNodes())
            $parent->removeChild($parent->childNodes->item(0));
        
        $parent->appendChild($element);
        
        return $doc;
    }
}