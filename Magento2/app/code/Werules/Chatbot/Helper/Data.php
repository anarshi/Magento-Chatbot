<?php
/**
 * Magento Chatbot Integration
 * Copyright (C) 2017
 *
 * This file is part of Werules/Chatbot.
 *
 * Werules/Chatbot is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Werules\Chatbot\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    protected $storeManager;
    protected $objectManager;
    protected $_messageModel;
    protected $_chatbotAPI;
    protected $_define;
    protected $_configPrefix;
    protected $_serializer;
    protected $_categoryHelper;
    protected $_categoryFactory;
    protected $_categoryCollectionFactory;
    protected $_storeManagerInterface;

    public function __construct(
        Context $context,
        ObjectManagerInterface $objectManager,
        \Magento\Framework\Serialize\Serializer\Json $serializer,
        StoreManagerInterface $storeManager,
        \Werules\Chatbot\Model\ChatbotAPIFactory $chatbotAPI,
        \Werules\Chatbot\Model\MessageFactory $message,
        \Magento\Catalog\Helper\Category $categoryHelper,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManagerInterface
    )
    {
        $this->objectManager = $objectManager;
        $this->_serializer = $serializer;
        $this->storeManager  = $storeManager;
        $this->_messageModel  = $message;
        $this->_chatbotAPI  = $chatbotAPI;
        $this->_configPrefix = '';
        $this->_define = new \Werules\Chatbot\Helper\Define;
        $this->_categoryHelper = $categoryHelper;
        $this->_categoryFactory = $categoryFactory;
        $this->_categoryCollectionFactory = $categoryCollectionFactory;
        $this->_storeManagerInterface = $storeManagerInterface;
        parent::__construct($context);
    }

    public function getConfigValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $field, ScopeInterface::SCOPE_STORE, $storeId
        );
    }

    public function logger($message) // TODO find a better way to to this
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/werules_chatbot.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info(var_export($message, true));
    }

    protected function getJsonResponse($success)
    {
        header_remove('Content-Type'); // TODO
        header('Content-Type: application/json'); // TODO
        if ($success)
            $arr = array("status" => "success", "success" => true);
        else
            $arr = array("status" => "error", "success" => false);
        return json_encode($arr);
    }

    public function getJsonSuccessResponse()
    {
        return $this->getJsonResponse(true);
    }

    public function getJsonErrorResponse()
    {
        return $this->getJsonResponse(false);
    }

    public function processMessage($message_id)
    {
        $message = $this->_messageModel->create();
        $message->load($message_id);

        if ($message->getMessageId())
        {
            if ($message->getDirection() == 0)
                $this->processIncomingMessage($message);
            else //if ($message->getDirection() == 1)
                $this->processOutgoingMessage($message);
        }
    }

    private function processIncomingMessage($message)
    {
        if ($message->getChatbotType() == $this->_define::MESSENGER_INT)
            $this->_configPrefix = 'werules_chatbot_messenger';

        $chatbotAPI = $this->_chatbotAPI->create();
        $chatbotAPI->load($message->getSenderId(), 'chat_id'); // TODO

        if (!($chatbotAPI->getChatbotapiId()))
        {
            $chatbotAPI->setEnabled($this->_define::DISABLED);
            $chatbotAPI->setChatbotType($message->getChatbotType()); // TODO
            $chatbotAPI->setChatId($message->getSenderId());
            $chatbotAPI->setConversationState($this->_define::CONVERSATION_STARTED);
            $chatbotAPI->setFallbackQty(0);
            $datetime = date('Y-m-d H:i:s');
            $chatbotAPI->setCreatedAt($datetime);
            $chatbotAPI->setUpdatedAt($datetime);
            $chatbotAPI->save();
        }

        $this->logger("Message ID -> " . $message->getMessageId());
        $this->logger("Message Content -> " . $message->getContent());
        $this->logger("ChatbotAPI ID -> " . $chatbotAPI->getChatbotapiId());

        $this->prepareOutgoingMessage($message);
    }

    private function prepareOutgoingMessage($message)
    {
        $responseContents = $this->processMessageRequest($message);

        if ($responseContents)
        {
            foreach ($responseContents as $content)
            {
                $outgoingMessage = $this->_messageModel->create();
                $outgoingMessage->setSenderId($message->getSenderId());
                $outgoingMessage->setContent($content['content']);
                $outgoingMessage->setContentType($content['content_type']); // TODO
                $outgoingMessage->setStatus($this->_define::PROCESSING);
                $outgoingMessage->setDirection($this->_define::OUTGOING);
                $outgoingMessage->setChatMessageId($message->getChatMessageId());
                $outgoingMessage->setChatbotType($message->getChatbotType());
                $datetime = date('Y-m-d H:i:s');
                $outgoingMessage->setCreatedAt($datetime);
                $outgoingMessage->setUpdatedAt($datetime);
                $outgoingMessage->save();

                $this->processOutgoingMessage($outgoingMessage->getMessageId());
            }

            $incomingMessage = $this->_messageModel->create();
            $incomingMessage->load($message->getMessageId()); // TODO
            $incomingMessage->setStatus($this->_define::PROCESSED);
            $datetime = date('Y-m-d H:i:s');
            $incomingMessage->setUpdatedAt($datetime);
            $incomingMessage->save();

//        $this->processOutgoingMessage($outgoingMessage);
        }
    }

    private function processOutgoingMessage($message_id)
    {
        $outgoingMessage = $this->_messageModel->create();
        $outgoingMessage->load($message_id);

        $chatbotAPI = $this->_chatbotAPI->create();
        $chatbotAPI->load($outgoingMessage->getSenderId(), 'chat_id'); // TODO

        $result = false;
        if ($outgoingMessage->getContentType() == $this->_define::CONTENT_TEXT)
            $result = $chatbotAPI->sendMessage($outgoingMessage);
        else if ($outgoingMessage->getContentType() == $this->_define::QUICK_REPLY)
            $result = $chatbotAPI->sendQuickReply($outgoingMessage);
        else if ($outgoingMessage->getContentType() == $this->_define::IMAGE_WITH_OPTIONS)
            $result = $chatbotAPI->sendImageWithOptions($outgoingMessage);

        if ($result)
        {
            $outgoingMessage->setStatus($this->_define::PROCESSED);
            $datetime = date('Y-m-d H:i:s');
            $outgoingMessage->setUpdatedAt($datetime);
            $outgoingMessage->save();
        }

        $this->logger("Outgoing Message ID -> " . $outgoingMessage->getMessageId());
        $this->logger("Outgoing Message Content -> " . $outgoingMessage->getContent());
    }

    private function processMessageRequest($message)
    {
        //$messageContent = $message->getContent();
        $responseContent = array();
        $commandResponses = false;
        $conversationStateResponses = false;

        if (count($responseContent) <= 0)
            $conversationStateResponses = $this->handleConversationState($message);
        if ($conversationStateResponses)
        {
            foreach ($conversationStateResponses as $conversationStateResponse)
            {
                array_push($responseContent, $conversationStateResponse);
            }
        }

        if (count($responseContent) <= 0)
            $commandResponses = $this->handleCommands($message);
        if ($commandResponses)
        {
            foreach ($commandResponses as $commandResponse)
            {
                array_push($responseContent, $commandResponse);
            }
        }

//        array_push($responseContent, array('content_type' => $this->_define::CONTENT_TEXT, 'content' => 'Dunno!'));

        return $responseContent;
    }

    private function handleConversationState($message)
    {
        $chatbotAPI = $this->_chatbotAPI->create();
        $chatbotAPI->load($message->getSenderId(), 'chat_id'); // TODO
        $result = false;

        if ($chatbotAPI->getConversationState() == $this->_define::CONVERSATION_LIST_CATEGORIES)
        {
            $result = $this->listProductsFromCategory($message);
        }

        return $result;
    }

    public function getCategoryById($category_id)
    {
        $category = $this->_categoryFactory->create();
        $category->load($category_id);

        return $category;
    }

    public function getCategoryByName($name)
    {
        return $this->getCategoriesByName($name)->getFirstItem();
    }

    public function getCategoriesByName($name)
    {
        $categoryCollection = $this->_categoryCollectionFactory->create();
        $categoryCollection = $categoryCollection->addAttributeToFilter('name', $name);

        return $categoryCollection;
    }

    public function getProductsFromCategoryId($category_id)
    {
        $productCollection = $this->getCategoryById($category_id)->getProductCollection();
        $productCollection->addAttributeToSelect('*');

        return $productCollection;
    }

    private function listProductsFromCategory($message)
    {
        $result = array();
        $productList = array();
        if ($message->getMessagePayload())
            $category = $this->getCategoryById($message->getMessagePayload());
        else
            $category = $this->getCategoryByName($message->getContent());

        $productCollection = $this->getProductsFromCategoryId($category->getId());

        foreach ($productCollection as $product)
        {
            $content = $this->getProductDetailsObject($product);
            array_push($productList, $content);
        }

        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::IMAGE_WITH_OPTIONS;
        $responseMessage['content'] = json_encode($productList);
        array_push($result, $responseMessage);

        return $result;
    }

    private function getProductDetailsObject($product)
    {
        $result = array();
        if ($product->getId())
        {
            $productName = $product->getName();
            $productUrl = $product->getProductUrl();
//            $productImage = $product->getImage();
            $productImage = $this->_storeManagerInterface->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product' . $product->getImage();
            // TODO add placeholder
            $options = array(
                array(
                    'type' => 'postback',
                    'title' => 'Add to cart',
                    'payload' => 'todo_here'
                ),
                array(
                    'type' => 'web_url',
                    'title' => "Visit product's page",
                    'url' => $productUrl
                )
            );
            $element = array(
                'title' => $productName,
                'item_url' => $productUrl,
                'image_url' => $productImage,
                'subtitle' => $this->excerpt($product->getShortDescription(), 60),
                'buttons' => $options
            );
            array_push($result, $element);
        }

        return $result;
    }

    public function excerpt($text, $size)
    {
        if (strlen($text) > $size)
        {
            $text = substr($text, 0, $size);
            $text = substr($text, 0, strrpos($text, " "));
            $etc = " ...";
            $text = $text . $etc;
        }

        return $text;
    }

    private function handleCommands($message)
    {
        $messageContent = $message->getContent();
        $serializedCommands = $this->getConfigValue($this->_configPrefix . '/general/commands_list');
        $this->logger($serializedCommands);
        $commandsList = $this->_serializer->unserialize($serializedCommands);
        $result = false;
        $state = false;
        if (is_array($commandsList))
        {
            foreach($commandsList as $command)
            {
                // if ($messageContent == $command['command_code'])
                if (strtolower($messageContent) == strtolower($command['command_code'])) // TODO add configuration for this
                {
                    if ($command['command_id'] == $this->_define::START_COMMAND_ID)
                    {
                        $result = $this->processStartCommand();
                    }
                    else if ($command['command_id'] == $this->_define::LIST_CATEGORIES_COMMAND_ID)
                    {
                        $result = $this->processListCategoriesCommand();
                        if ($result)
                            $state = $this->_define::CONVERSATION_LIST_CATEGORIES;
                    }
                    else if ($command['command_id'] == $this->_define::SEARCH_COMMAND_ID)
                    {
                        $result = $this->processSearchCommand();
                    }
                    else if ($command['command_id'] == $this->_define::LOGIN_COMMAND_ID)
                    {
                        $result = $this->processLoginCommand();
                    }
                    else if ($command['command_id'] == $this->_define::LIST_ORDERS_COMMAND_ID)
                    {
                        $result = $this->processListOrdersCommand();
                    }
                    else if ($command['command_id'] == $this->_define::REORDER_COMMAND_ID)
                    {
                        $result = $this->processReorderCommand();
                    }
                    else if ($command['command_id'] == $this->_define::ADD_TO_CART_COMMAND_ID)
                    {
                        $result = $this->processAddToCartCommand();
                    }
                    else if ($command['command_id'] == $this->_define::CHECKOUT_COMMAND_ID)
                    {
                        $result = $this->processCheckoutCommand();
                    }
                    else if ($command['command_id'] == $this->_define::CLEAR_CART_COMMAND_ID)
                    {
                        $result = $this->processClearCartCommand();
                    }
                    else if ($command['command_id'] == $this->_define::TRACK_ORDER_COMMAND_ID)
                    {
                        $result = $this->processTrackOrderCommand();
                    }
                    else if ($command['command_id'] == $this->_define::SUPPORT_COMMAND_ID)
                    {
                        $result = $this->processSupportCommand();
                    }
                    else if ($command['command_id'] == $this->_define::SEND_EMAIL_COMMAND_ID)
                    {
                        $result = $this->processSendEmailCommand();
                    }
                    else if ($command['command_id'] == $this->_define::CANCEL_COMMAND_ID)
                    {
                        $result = $this->processCancelCommand();
                    }
                    else if ($command['command_id'] == $this->_define::HELP_COMMAND_ID)
                    {
                        $result = $this->processHelpCommand();
                    }
                    else if ($command['command_id'] == $this->_define::ABOUT_COMMAND_ID)
                    {
                        $result = $this->processAboutCommand();
                    }
                    else if ($command['command_id'] == $this->_define::LOGOUT_COMMAND_ID)
                    {
                        $result = $this->processLogoutCommand();
                    }
                    else if ($command['command_id'] == $this->_define::REGISTER_COMMAND_ID)
                    {
                        $result = $this->processRegisterCommand();
                    }
                    break;
                }
            }
        }

        if ($state)
            $this->updateConversationState($message->getSenderId(), $state);

        return $result;
    }

    private function updateConversationState($sender_id, $state)
    {
        $chatbotAPI = $this->_chatbotAPI->create();
        $chatbotAPI->load($sender_id, 'chat_id'); // TODO

        if ($chatbotAPI->getChatbotapiId())
        {
            $chatbotAPI->setConversationState($state);
            $datetime = date('Y-m-d H:i:s');
            $chatbotAPI->setUpdatedAt($datetime);
            $chatbotAPI->save();

            return true;
        }

        return false;
    }

    private function processStartCommand()
    {
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the START command!';
        return $responseMessage;
    }

    private function processListCategoriesCommand()
    {
        $result = array();
        $categories = $this->getStoreCategories(false,false,true);
        $quickReplies = array();
        foreach ($categories as $category)
        {
            $categoryName = $category->getName();
            if ($categoryName)
            {
                $quickReply = array(
                    'content_type' => 'text', // TODO messenger pattern
                    'title' => $categoryName,
                    'payload' => $category->getId()
                );
                array_push($quickReplies, $quickReply);
            }
        }
        $contentObject = new \stdClass();
        $contentObject->message = 'Pick one of the following categories.';
        $contentObject->quick_replies = $quickReplies;
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::QUICK_REPLY;
        $responseMessage['content'] = json_encode($contentObject);
        array_push($result, $responseMessage);
        return $result;
    }

    private function processSearchCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the SEARCH command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processLoginCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the LOGIN command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processListOrdersCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the LIST_ORDERS command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processReorderCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the REORDER command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processAddToCartCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the ADD_TO_CART command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processCheckoutCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the CHECKOUT command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processClearCartCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the CLEAR_CART command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processTrackOrderCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the TRACK_ORDER command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processSupportCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the SUPPORT command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processSendEmailCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the SEND_EMAIL command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processCancelCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the CANCEL command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processHelpCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the HELP command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processAboutCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the ABOUT command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processLogoutCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the LOGOUT command!';
        array_push($result, $responseMessage);
        return $result;
    }

    private function processRegisterCommand()
    {
        $result = array();
        $responseMessage = array();
        $responseMessage['content_type'] = $this->_define::CONTENT_TEXT;
        $responseMessage['content'] = 'you just sent the REGISTER command!';
        array_push($result, $responseMessage);
        return $result;
    }

//    public function getConfig($code, $storeId = null)
//    {
//        return $this->getConfigValue(self::XML_PATH_CHATBOT . $code, $storeId);
//    }
    public function getStoreCategories($sorted = false, $asCollection = false, $toLoad = true)
    {
        return $this->_categoryHelper->getStoreCategories($sorted , $asCollection, $toLoad);
//        return $this->_categoryFactory->create()->getCollection();
    }
}